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
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
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
 *
 * Two orderings in here are load-bearing, and both are about what the *next* attempt can still see:
 *
 *  - the content is recorded converted before its PDFs are hidden, never the other way round. A
 *    hidden PDF is invisible to sourceFilesFor(), so a crash between the two orderings' statements
 *    would leave a content whose retry finds no source, marks it failed for good, and has already
 *    had the pages of the previous attempt cleaned up: the document would be gone.
 *  - nothing is written to the archive without the conversion row proving, that moment, that this
 *    worker still holds the content. A worker the reconciler has given up on has had its page rows
 *    deleted already, and a second attempt may be writing the content's pages right now.
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
            // Nothing in the archive is touched before the row itself says this worker holds the
            // content - not even the reservation. A job that outlived its claim would otherwise
            // reserve, and then roll back, a content that belongs to another attempt.
            if (! $this->stillOurs($conversion, $stage)) {
                return;
            }

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

                    if (! $this->stillOurs($conversion, $stage)) {
                        return;
                    }

                    $remote = $source->remoteFolder().'/'.$source->remoteFileName();
                    $pdf = $workspace.DIRECTORY_SEPARATOR.$source->remoteFileName();

                    try {
                        $this->files->download($remote, $pdf);
                    } catch (FileStoreException $exception) {
                        // Thousands of the archive's rows name a file that is not on the site any more.
                        // There is nothing to convert and nothing to wait for, so the content is given
                        // up on at once instead of spending three attempts on it - and it is recorded
                        // as its own outcome, so a stale row is never mistaken for an FTP problem.
                        if ($exception->absent) {
                            $this->giveUp($conversion, Stage::Missing, "the source PDF is not on the file store: {$remote}", retryable: false);

                            return;
                        }

                        throw $exception;
                    }

                    $stage = Stage::Render;

                    if (! $this->stillOurs($conversion, $stage)) {
                        return;
                    }

                    $images = $this->renderer->render($pdf, $workspace.DIRECTORY_SEPARATOR.'pages');

                    foreach ($images as $image) {
                        $stage = Stage::Thumbnail;

                        if (! $this->stillOurs($conversion, $stage)) {
                            return;
                        }

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
                }

                $stage = Stage::Finish;

                // The last check before anything in the archive is made final. If this attempt has
                // been taken over, its page rows are already deleted, and marking the content
                // converted would leave the archive with a converted content and no pages - the
                // verdict that hid 10,601 documents in the old pipeline.
                if (! $this->stillOurs($conversion, $stage)) {
                    return;
                }

                $this->archive->markConverted($contentId);

                if (! $this->queue->succeed($conversion, $pages)) {
                    // The heartbeat above held, so the content cannot have gone stale in between
                    // (that takes converter.failure.stale_after_minutes of silence) - but if it ever
                    // does happen, the pages are written and the archive says converted while the
                    // panel does not. That is shouted, not rolled back: the rows are the truth.
                    Log::critical(sprintf(
                        'Content %s was marked converted with %d page(s) in the archive, but conversion %d could not be recorded as done; the panel will show it as unfinished.',
                        $contentId,
                        $pages,
                        $conversion->id,
                    ));

                    return;
                }

                // Hiding the PDFs is deliberately the very last step, after the content is recorded
                // converted both in the archive and here, and it deliberately cannot fail the
                // conversion. sourceFilesFor() only reads MVDContent rows with Deleted = 0, so a
                // source hidden before the verdict is a document the next attempt cannot find: it
                // would see no PDF row, mark the content failed for good, and the pages of the
                // attempt it is retrying are gone. A source still visible is cosmetic by comparison.
                foreach ($sources as $source) {
                    $this->hideSource($source->mvdId);
                }
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

        // Ownership before undoing anything. If the reconciler decided this attempt was dead, its
        // page rows are already deleted and the content may be in another worker's hands: rollBack()
        // would then delete that worker's pages and its uploaded images, and giveUp() would take the
        // content off it.
        if (! $this->stillOurs($conversion, $stage)) {
            return;
        }

        try {
            $this->rollBack($conversion);
        } catch (Throwable $rollback) {
            report($rollback);
        }

        $this->giveUp($conversion, $stage, Str::limit($exception->getMessage(), 400), $retryable);
    }

    /**
     * Records the failure and hands the content back to the archive: free again when it will be
     * tried once more, marked failed when the failure was a verdict about the document itself.
     */
    private function giveUp(Conversion $conversion, Stage $stage, string $reason, bool $retryable): void
    {
        if (! $this->queue->fail($conversion, $stage, $reason, $retryable)) {
            // The row is not ours any more, so the failure belongs to nobody: whoever holds the
            // content now decides what happens to it, and its reservation is theirs to release.
            $this->abandon($conversion, $stage);

            return;
        }

        // markFailed writes the archive's own "beyond help" marker, and that marker takes the content
        // out of discovery for good - nothing finds it again until somebody clears it by hand. It is
        // only ever right for a verdict about the document: no PDF row, or a PDF the renderer
        // refuses, which is exactly what a failure that was never retryable is. A content that only
        // ran out of attempts against a transient failure is a different thing entirely - a full
        // staging drive, an FTP site that is down, an archive that is not answering - and marking
        // those would walk through the queue burying half a million perfectly good documents three
        // attempts at a time. They are released instead, so the panel's own row is the only record
        // of the failure and a person can put the content back.
        if ($conversion->status === ConversionStatus::Failed && ! $retryable) {
            $this->handBack($conversion->content_id, fn () => $this->archive->markFailed($conversion->content_id));

            return;
        }

        $this->handBack($conversion->content_id, fn () => $this->archive->release($conversion->content_id));
    }

    /**
     * Puts the reservation back, or says loudly that it could not. A content whose reservation is
     * still standing is invisible to discovery, and nothing else in the pipeline will ever clear it:
     * the conversion is no longer claimed, so the reconciler will not look at it either.
     */
    private function handBack(string $contentId, Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $exception) {
            report($exception);

            Log::critical(sprintf(
                'The reservation on content %s could not be handed back (%s); GeneralContent.Reserved has to be cleared by hand before the content can be converted again.',
                $contentId,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * Pushes the heartbeat forward and answers whether this worker still holds the conversion.
     *
     * It is asked before every step that can run for minutes, because the gap between two heartbeats
     * is what the reconciler judges a worker by: it has to stay well inside
     * converter.failure.stale_after_minutes, and a download (converter.ftp.transfer_timeout, tried
     * converter.ftp.attempts times) and a render (converter.render.timeout) are the two steps long
     * enough to cross it on their own. Before this, the first heartbeat of a conversion came only
     * after the download *and* the render of the first source - 45 minutes with the shipped
     * timeouts, against a 60 minute staleness window that starts counting when the dispatcher
     * claims the row, not when a worker picks the job up.
     */
    private function stillOurs(Conversion $conversion, Stage $stage): bool
    {
        if ($this->queue->heartbeat($conversion)) {
            return true;
        }

        $this->abandon($conversion, $stage);

        return false;
    }

    /**
     * Stops working on a conversion this worker no longer holds, and undoes nothing at all.
     *
     * Rolling back here would be precisely the wrong move: the reconciler has already deleted this
     * attempt's page rows, released the content and queued it again, so anything in the archive now
     * belongs to the attempt that holds it. A page row this process wrote after losing the claim is
     * in nobody's ledger, and the next attempt's rollBack() still finds it - imagePagesFor() reads
     * the content's image rows straight out of the archive - so it is cleaned up there.
     */
    private function abandon(Conversion $conversion, Stage $stage): void
    {
        Log::warning(sprintf(
            'Conversion %d of content %s was taken over while this worker was %s; it stopped without undoing anything.',
            $conversion->id,
            $conversion->content_id,
            $stage->label(),
        ));
    }

    /**
     * Flags a converted content's PDF deleted, which is how the archive hides a source whose pages
     * exist. A failure is logged and no more: by the time this runs the pages are stored and the
     * content is converted, and failing it now would delete the very pages that succeeded.
     */
    private function hideSource(string $mvdId): void
    {
        try {
            $this->archive->softDeleteSource($mvdId);
        } catch (Throwable $exception) {
            report($exception);

            Log::warning(sprintf(
                'MVDContent %s could not be flagged deleted; its content is converted and its pages are stored, so the PDF is only still visible alongside them.',
                $mvdId,
            ));
        }
    }
}
