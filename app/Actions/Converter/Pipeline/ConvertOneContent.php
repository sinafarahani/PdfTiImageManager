<?php

namespace App\Actions\Converter\Pipeline;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\RenderFailed;
use App\Actions\Converter\Render\Thumbnailer;
use App\Models\Conversion;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Throwable;

/**
 * Converts one content: take it in the archive, fetch its PDF, render the pages, store each page,
 * then mark the content converted and delete the working folder.
 *
 * A conversion is all or nothing. A single database transaction cannot span the uploads - it would
 * hold locks on a 140-million-row table for minutes, which is where the old pipeline's deadlocks came
 * from - so each page is written atomically and the conversion as a whole is made atomic by rolling
 * back: every page row and every uploaded file is recorded in conversion_pages *before* it is
 * created, so a failure removes exactly what this attempt made and the content is left as it was.
 */
class ConvertOneContent
{
    public function __construct(
        private readonly ArchiveGateway $archive,
        private readonly FileStore $files,
        private readonly PageRenderer $renderer,
        private readonly Thumbnailer $thumbnailer,
        private readonly ConversionQueue $queue,
        private readonly ContentWorkspace $workspace,
    ) {}

    public function convert(Conversion $conversion): void
    {
        $contentId = $conversion->content_id;
        $owner = (string) ($conversion->worker ?: 'converter');
        $stage = Stage::Reserve;

        try {
            if (! $this->archive->reserve($contentId, $owner)) {
                // Somebody else has it. We hold nothing in the archive, so nothing is released here.
                $this->queue->fail($conversion, Stage::Reserve, 'the archive has this content reserved by another worker', retryable: true);

                return;
            }

            $stage = Stage::Metadata;
            $this->rollBack($conversion);

            $sources = array_values(array_filter(
                $this->archive->sourceFilesFor($contentId),
                fn (SourceFile $file): bool => $file->isPdf(),
            ));

            if ($sources === []) {
                // The old pipeline marked a content like this *converted*, which hid the document for
                // good: 10,601 contents in the archive have that verdict and no pages.
                $this->giveUp($conversion, Stage::Metadata, 'the content has no PDF source row', retryable: false);

                return;
            }

            $siteId = $this->archive->currentFileSite()->id;
            $storesImageInDatabase = $this->archive
                ->storeModeFor($conversion->profile_id ?? $this->archive->profileIdFor($contentId) ?? 0)
                ->storesImageInDatabase();

            // One timestamp for the whole content: MVDContent.CreateDateTime and the FTP folder are
            // both derived from it, so a row can never point at a folder its file is not in.
            $createDateTime = now()->format('Y-m-d H:i:s');
            $workspace = $this->workspace->open($contentId);
            $pages = 0;

            try {
                foreach ($sources as $source) {
                    $stage = Stage::Download;
                    $pdf = $workspace.DIRECTORY_SEPARATOR.$source->remoteFileName();
                    $this->files->download($source->remoteFolder().'/'.$source->remoteFileName(), $pdf);

                    $stage = Stage::Render;
                    $images = $this->renderer->render($pdf, $workspace.DIRECTORY_SEPARATOR.'pages');

                    foreach ($images as $image) {
                        $stage = Stage::Thumbnail;
                        $this->queue->heartbeat($conversion);
                        $thumbnail = $this->thumbnailer->make($image);

                        $stage = Stage::Write;
                        $sequence = ++$pages;
                        $this->queue->recordPage($conversion, $sequence);

                        $mvdId = $this->archive->insertPage(new PageInsert(
                            contentId: $contentId,
                            seqPageNo: $sequence,
                            createDateTime: $createDateTime,
                            format: (string) config('converter.archive.image_format'),
                            ftpSiteId: $siteId,
                            thumbnail: $thumbnail,
                            image: $storesImageInDatabase ? File::get($image) : null,
                        ));

                        $page = $this->queue->recordPage($conversion, $sequence, $mvdId);

                        $stage = Stage::Upload;

                        if ($storesImageInDatabase) {
                            $this->queue->markPageUploaded($page);

                            continue;
                        }

                        $remotePath = $this->remotePathFor($createDateTime, $mvdId);
                        $bytes = $this->files->upload($image, $remotePath);
                        $this->queue->markPageUploaded($page, $remotePath, $bytes);
                    }

                    // Only once its pages exist: the archive hides a source whose pages are stored.
                    $stage = Stage::Finish;
                    $this->archive->softDeleteSource($source->mvdId);
                }

                $this->archive->markConverted($contentId);
                $this->queue->succeed($conversion, $pages);
            } finally {
                // Neither the stage nor an exception from here may hide why the conversion failed:
                // the working folder always goes, and a problem doing that is logged, not thrown.
                $this->cleanUp($contentId);
            }
        } catch (Throwable $exception) {
            $this->handleFailure($conversion, $stage, $exception);
        }
    }

    /**
     * Closes the file store session and deletes the working folder, whatever happened before.
     */
    private function cleanUp(string $contentId): void
    {
        try {
            $this->files->disconnect();
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $this->workspace->close($contentId);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Where a page image lives on the file store: the folder of its own CreateDateTime, then its
     * MVDContent ID with the extension the archive's format names ("Image/jpg" gives "jpg").
     */
    private function remotePathFor(string $createDateTime, string $mvdId): string
    {
        $format = (string) config('converter.archive.image_format');
        $extension = str_contains($format, '/') ? explode('/', $format)[1] : 'jpg';

        return SourceFile::folderFor($createDateTime).'/'.$mvdId.'.'.strtolower(trim($extension));
    }

    /**
     * Removes everything an earlier attempt at this content created: the page rows in the archive
     * (their thumbnails and images follow through the cascading foreign keys) and the images on the
     * file store. This is what used to be done by hand with rebuild scripts, and it is exact rather
     * than a guess, because each page was recorded before it was created.
     *
     * Page rows the previous pipeline left behind are included: they are not in our ledger, so they
     * are read back from the archive and their file names rebuilt from their own timestamps.
     */
    private function rollBack(Conversion $conversion): void
    {
        $mvdIds = [];

        foreach ($this->archive->imagePagesFor($conversion->content_id) as $stray) {
            $this->deleteQuietly($stray->remoteFolder().'/'.$stray->remoteFileName());
            $mvdIds[] = $stray->mvdId;
        }

        foreach ($conversion->pages()->get() as $page) {
            if ($page->remote_path !== null) {
                $this->deleteQuietly($page->remote_path);
            }

            if ($page->mvd_id !== null) {
                $mvdIds[] = $page->mvd_id;
            }
        }

        if ($mvdIds !== []) {
            $this->archive->deletePages(array_values(array_unique($mvdIds)));
        }

        $conversion->pages()->delete();
    }

    /**
     * A file that is already gone, or a store that cannot be reached, must not stop a rollback: the
     * page rows are the part that matters, and a leftover image is found again by the next attempt.
     */
    private function deleteQuietly(string $remotePath): void
    {
        try {
            $this->files->delete($remotePath);
        } catch (FileStoreException) {
            // nothing to do
        }
    }

    private function handleFailure(Conversion $conversion, Stage $stage, Throwable $exception): void
    {
        report($exception);

        $retryable = match (true) {
            $exception instanceof RenderFailed => $exception->retryable,
            $exception instanceof FileStoreException => $exception->transient,
            $exception instanceof WorkspaceUnavailable, $exception instanceof QueryException => true,

            // An unexpected failure is worth another attempt: the attempt count bounds it anyway.
            default => true,
        };

        try {
            $this->rollBack($conversion);
        } catch (Throwable $rollback) {
            report($rollback);
        }

        $this->giveUp($conversion, $stage, Str::limit($exception->getMessage(), 400), $retryable);
    }

    /**
     * Records the failure and hands the content back to the archive: free again when it will be
     * tried once more, marked failed when it will not.
     */
    private function giveUp(Conversion $conversion, Stage $stage, string $reason, bool $retryable): void
    {
        $this->queue->fail($conversion, $stage, $reason, $retryable);

        if ($conversion->status === ConversionStatus::Failed) {
            $this->archive->markFailed($conversion->content_id);

            return;
        }

        $this->archive->release($conversion->content_id);
    }
}
