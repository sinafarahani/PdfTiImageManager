<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FtpSite;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\RecordingArchive;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Archive\StoreMode;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\RecordingFileStore;
use App\Actions\Converter\Pipeline\ContentWorkspace;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\ConvertOneContent;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\Thumbnailer;
use App\Models\Conversion;
use App\Models\ConversionPage;
use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs one content through the real pipeline and reports, line by line, what it writes.
 *
 * Without --write nothing is changed anywhere. The content is looked up in the live archive, its PDF
 * is really downloaded from the live FTP site and really rendered by pdf2img, and then the archive
 * writes and the uploads are recorded instead of performed (RecordingArchive, RecordingFileStore) and
 * printed: every MVDContent row, every remote path, every byte count. That is the answer to "what
 * will this do to my archive", given by the code that would do it rather than by reading it.
 *
 * With --write the same content goes through the pipeline the queue worker uses, with no decorators
 * in the way, so a canary conversion before turning the panel on is one command.
 *
 * Both are meant to be run with the panel stopped. Nothing here coordinates with a dispatcher that is
 * handing out work at the same moment: a content another worker already holds is refused, but one
 * claimed a second later would end up with two processes in the same workspace folder.
 */
class TryContent extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:try
        {contentId : GeneralContent.ID of the content to run}
        {--write : Convert it for real instead of only reporting what would happen}';

    /**
     * @var string
     */
    protected $description = 'Run one content through the pipeline and report exactly what it writes';

    /**
     * The write mode values SqlServerArchive opens its gate for. Repeated here so that --write can be
     * refused before anything is built and the operator gets one sentence about .env instead of a
     * RuntimeException from the first page insert. A disagreement between the two lists can only fail
     * safe: the archive itself is still the gate that decides.
     *
     * @var list<string>
     */
    private const array WRITE_MODES_ON = ['on', 'true', '1', 'yes', 'enabled'];

    /**
     * Remote files a report names one by one before it starts counting them instead.
     */
    private const int DELETIONS_LISTED = 10;

    public function handle(
        ArchiveGateway $archive,
        ConversionQueue $queue,
        ContentWorkspace $workspace,
        PageRenderer $renderer,
        Thumbnailer $thumbnailer,
    ): int {
        $contentId = trim((string) $this->argument('contentId'));

        if ($contentId === '') {
            $this->error('Give the GeneralContent.ID of the content to run.');

            return self::FAILURE;
        }

        // Checked before anything else, because everything else costs an archive query and a real
        // conversion is not something to start and then refuse.
        if ($this->option('write') && ! $this->writesAreAllowed()) {
            $this->error(sprintf(
                'The archive is in dry-run mode (converter.archive.write_mode is "%s"), so --write would be refused at the first page row.',
                (string) config('converter.archive.write_mode'),
            ));
            $this->line('Set CONVERTER_WRITE_MODE=on in .env to let this pipeline write to the archive, then run it again.');

            return self::FAILURE;
        }

        $queued = $this->queuedConversion($contentId);

        // A run needs the content's queue row claimed, and a content somebody is converting right now
        // has one. Taking it over would point two pipelines at one document - and in a dry run it
        // would also delete the workspace folder the other one is rendering into.
        if ($queued?->status === ConversionStatus::Claimed) {
            $this->error(sprintf(
                'Content %s is being converted right now by %s (last heartbeat %s).',
                $contentId,
                $queued->worker ?? 'an unknown worker',
                $queued->heartbeat_at?->diffForHumans() ?? 'never',
            ));
            $this->line('Wait for it to finish, or run converters:reconcile if its worker is gone.');

            return self::FAILURE;
        }

        $started = microtime(true);

        try {
            return $this->option('write')
                ? $this->convertForReal($contentId, $queued, $archive)
                : $this->reportWhatWouldHappen($contentId, $queued, $archive, $queue, $workspace, $renderer, $thumbnailer);
        } catch (QueryException $exception) {
            // This is the first command an operator points at the archive, usually while the
            // ARCHIVE_DB_* settings are still being worked out, so a connection that is not there yet
            // has to read as one sentence rather than as a stack trace. The driver's own message is
            // taken from underneath the QueryException, whose message repeats the whole statement.
            $this->newLine();
            $this->error('The archive could not be read: '.($exception->getPrevious()?->getMessage() ?: $exception->getMessage()));
            $this->line('Nothing was changed anywhere. Check the ARCHIVE_DB_* settings in .env; `php artisan converters:preflight` tests the connection, the FTP site and pdf2img in one pass.');

            return self::FAILURE;
        } finally {
            $this->newLine();
            $this->line(sprintf('Elapsed %.1f second(s).', microtime(true) - $started));
        }
    }

    /**
     * The dry run: the whole pipeline, with the archive writes and the uploads recorded instead of
     * performed.
     */
    private function reportWhatWouldHappen(
        string $contentId,
        ?Conversion $queued,
        ArchiveGateway $archive,
        ConversionQueue $queue,
        ContentWorkspace $workspace,
        PageRenderer $renderer,
        Thumbnailer $thumbnailer,
    ): int {
        $this->info("Dry run of content {$contentId}. Every read is real; every write is only reported.");

        $recordingArchive = new RecordingArchive($archive);

        // Resolved here rather than injected into handle(): building the FTP client asks the archive
        // which site is current, and neither refusal above should have to pay for that query.
        $recordingFiles = new RecordingFileStore($this->laravel->make(FileStore::class));

        $survey = $this->surveyArchive($archive, $contentId, $queued?->profile_id);
        $this->printArchive($survey);
        $this->printQueueRow($queued);

        $connection = DB::connection();

        // The whole run happens inside a transaction that is always rolled back, so the panel's queue
        // is left exactly as it was found. This is not tidiness. The pipeline writes its progress
        // through the queue - it counts an attempt on a failure, it deletes the page ledger of an
        // earlier attempt, it marks the conversion done - and none of that may stick to a run that
        // wrote nothing: a dry run that spent one of the content's three attempts, or that left a row
        // saying "converted, 12 pages" for a conversion that never happened, would make the panel lie
        // about the archive.
        $connection->beginTransaction();

        // pdf2img's own output is part of the report, and it has to be kept as it is produced: the
        // workspace folder is deleted before a line of the report is printed, and a run that fails
        // half way through a content would otherwise be reported as having rendered only the pages
        // that got as far as a page row.
        $recordingRenderer = new class($renderer) implements PageRenderer
        {
            /**
             * @var list<array{path: string, bytes: int}>
             */
            public array $rendered = [];

            public function __construct(private readonly PageRenderer $renderer) {}

            /**
             * @return list<string>
             */
            public function render(string $pdfPath, string $outputDirectory): array
            {
                $pages = $this->renderer->render($pdfPath, $outputDirectory);

                foreach ($pages as $page) {
                    clearstatcache(true, $page);
                    $this->rendered[] = ['path' => $page, 'bytes' => (int) @filesize($page)];
                }

                return $pages;
            }
        };

        try {
            $conversion = $this->claim($contentId, $queued, $survey['profileId']);

            $pipeline = new ConvertOneContent(
                $recordingArchive,
                $recordingFiles,
                $recordingRenderer,
                $thumbnailer,
                $queue,
                $workspace,
            );

            $pipeline->convert($conversion);

            $outcome = $this->outcomeOf($conversion);
        } finally {
            $this->rollBackQuietly($connection);
        }

        $this->printRun($survey, $recordingFiles, $recordingArchive, $recordingRenderer->rendered);
        $this->printPlannedPages($survey, $recordingArchive, $recordingFiles);
        $this->printPlannedWrites($survey, $recordingArchive, $recordingFiles);
        $this->compareWithArchive($survey, count($recordingArchive->insertedPages()));

        return $this->printOutcome($outcome, 'would be written');
    }

    /**
     * The canary: the pipeline the queue worker runs, for one content, now.
     */
    private function convertForReal(string $contentId, ?Conversion $queued, ArchiveGateway $archive): int
    {
        $this->warn("Converting content {$contentId} for real. The archive and the FTP site will be changed.");

        $survey = $this->surveyArchive($archive, $contentId, $queued?->profile_id);
        $this->printArchive($survey);
        $this->printQueueRow($queued);
        $this->compareWithArchive($survey, null);

        $conversion = $this->claim($contentId, $queued, $survey['profileId']);

        // The container's own pipeline, with nothing wrapped around it: whatever this does here is
        // what a worker does to every other content.
        $this->laravel->make(ConvertOneContent::class)->convert($conversion);

        $outcome = $this->outcomeOf($conversion);

        $this->newLine();
        $this->line('Pages written into MVDContent (ContentID '.$contentId.' on every one):');

        /** @var ConversionPage $page */
        foreach ($conversion->pages()->orderBy('seq')->get() as $page) {
            $this->line(sprintf(
                '  %4d  MVDContent %s  %s  %s',
                $page->seq,
                $page->mvd_id ?? 'no row was written',
                $page->bytes === null ? 'no image' : $this->bytes((int) $page->bytes),
                $page->remote_path === null
                    ? 'stored in the database (ImageLayer)'
                    : $this->remotePath($survey['site'], $page->remote_path),
            ));
        }

        $this->newLine();
        $this->line(sprintf(
            'The content now has %d image page row(s) in the archive.',
            count($archive->imagePagesFor($contentId)),
        ));

        return $this->printOutcome($outcome, 'were written');
    }

    /**
     * What the archive holds for this content before anything runs. The profile is read the way the
     * pipeline reads it, from the queue row first, so the report and the run agree.
     *
     * @return array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}
     */
    private function surveyArchive(ArchiveGateway $archive, string $contentId, ?int $profileId): array
    {
        $profileId ??= $archive->profileIdFor($contentId);

        /** @var list<SourceFile> $sources */
        $sources = array_values(array_filter(
            $archive->sourceFilesFor($contentId),
            fn (SourceFile $file): bool => $file->isPdf(),
        ));

        return [
            'profileId' => $profileId,
            'storeMode' => $archive->storeModeFor($profileId ?? 0),
            'site' => $archive->currentFileSite(),
            'sources' => $sources,
            'imagePages' => $archive->imagePagesFor($contentId),
        ];
    }

    /**
     * @param  array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}  $survey
     */
    private function printArchive(array $survey): void
    {
        $site = $survey['site'];

        $this->newLine();
        $this->line('In the archive now');
        $this->line(sprintf(
            '  profile %s, StoreMode "%s" - %s',
            $survey['profileId'] === null ? 'unknown' : (string) $survey['profileId'],
            $survey['storeMode']->value,
            $survey['storeMode']->storesImageInDatabase()
                ? 'page images are stored in the database (ImageLayer), not uploaded'
                : 'page images go to FTP and only the thumbnail is stored in the database',
        ));
        $this->line(sprintf('  FTP site %d: %s (paths below are inside the site folder "%s")', $site->id, (string) $site, $site->folder));

        $this->line(sprintf('  %d PDF source row(s):', count($survey['sources'])));

        foreach ($survey['sources'] as $source) {
            $this->line(sprintf(
                '    MVDContent %s  "%s"  %s  CreateDateTime %s',
                $source->mvdId,
                $source->pageNo,
                $source->format,
                $source->createDateTime,
            ));
            $this->line('      '.$this->remotePath($site, $source->remoteFolder().'/'.$source->remoteFileName()));
        }

        $this->line(sprintf('  %d image page row(s) on this content already.', count($survey['imagePages'])));
    }

    private function printQueueRow(?Conversion $queued): void
    {
        $this->newLine();
        $this->line('In the panel\'s queue');

        if ($queued === null) {
            $this->line('  the content is not queued; a row is created for this run.');

            return;
        }

        $this->line(sprintf(
            '  conversion %d: %s, %d attempt(s)%s',
            $queued->id,
            $queued->status->label(),
            $queued->attempts,
            $queued->failure_reason === null ? '' : ', last failure: '.$queued->failure_reason,
        ));
    }

    /**
     * @param  array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}  $survey
     * @param  list<array{path: string, bytes: int}>  $rendered
     */
    private function printRun(array $survey, RecordingFileStore $files, RecordingArchive $archive, array $rendered): void
    {
        $this->newLine();
        $this->line('What the run did');

        foreach ($files->downloads() as $download) {
            $this->line(sprintf(
                '  downloaded %s -> %s',
                $this->remotePath($survey['site'], $download['remotePath']),
                $this->bytes($download['bytes']),
            ));
        }

        if ($files->downloads() === []) {
            $this->line('  nothing was downloaded.');
        }

        $this->line($rendered === []
            ? '  pdf2img produced no page image.'
            : sprintf(
                '  pdf2img produced %d page image(s), %s.',
                count($rendered),
                $this->sizeRange(array_map(fn (array $page): int => $page['bytes'], $rendered)),
            ));

        $thumbnails = array_map(
            fn (PageInsert $page): int => strlen($page->thumbnail),
            array_values($archive->insertedPages()),
        );

        if ($thumbnails !== []) {
            $this->line(sprintf('  %d thumbnail(s), %s.', count($thumbnails), $this->sizeRange($thumbnails)));
        }
    }

    /**
     * @param  array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}  $survey
     */
    private function printPlannedPages(array $survey, RecordingArchive $archive, RecordingFileStore $files): void
    {
        $pages = $archive->insertedPages();

        if ($pages === []) {
            return;
        }

        /** @var array<string, array{localPath: string, remotePath: string, bytes: int}> $uploads */
        $uploads = [];

        foreach ($files->uploads() as $upload) {
            // The image is uploaded under the MVDContent id the archive answered with, so the file
            // name is what ties an upload back to its page row.
            $uploads[pathinfo($upload['remotePath'], PATHINFO_FILENAME)] = $upload;
        }

        $this->newLine();
        $this->line('Page rows it would insert into MVDContent (ContentID '.array_values($pages)[0]->contentId.' on every one):');
        $this->line('  The ids below are placeholders. MVDContent.ID defaults to newsequentialid(), so the archive');
        $this->line('  generates the real one on the insert - and the image file is named after it, which makes the');
        $this->line('  file names below placeholders too. Every folder, format and byte count is real.');

        foreach ($pages as $mvdId => $page) {
            $this->line(sprintf(
                '  SeqPageNo %-4d PageNo "%s"  %s  CreateDateTime %s  FtpSiteID %d  ID %s',
                $page->seqPageNo,
                $page->pageNo(),
                $page->format,
                $page->createDateTime,
                $page->ftpSiteId,
                $mvdId,
            ));
            $this->line(sprintf('      ThumbLayer %s', $this->bytes(strlen($page->thumbnail))));

            $upload = $uploads[$mvdId] ?? null;

            if ($upload !== null) {
                $this->line(sprintf(
                    '      image %s -> %s',
                    $this->bytes($upload['bytes']),
                    $this->remotePath($survey['site'], $upload['remotePath']),
                ));

                continue;
            }

            $this->line($page->image === null
                ? '      no image was uploaded and none was stored in the database'
                : sprintf('      image %s -> ImageLayer row, nothing is uploaded', $this->bytes(strlen($page->image))));
        }
    }

    /**
     * @param  array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}  $survey
     */
    private function printPlannedWrites(array $survey, RecordingArchive $archive, RecordingFileStore $files): void
    {
        $this->newLine();
        $this->line('Writes it would have made (not one of them ran):');

        foreach (array_count_values($archive->operations()) as $operation => $times) {
            $this->line(sprintf('  archive  %-18s %d', $operation, $times));
        }

        if ($archive->operations() === []) {
            $this->line('  archive  nothing at all');
        }

        $this->line(sprintf('  FTP      %-18s %d', 'upload', count($files->uploads())));
        $this->line(sprintf('  FTP      %-18s %d', 'delete', count($files->deletions())));

        // The deletions are the images of an earlier attempt, so they are named rather than counted:
        // they are the files a person would otherwise have to hunt for by hand. Long lists are cut
        // off, because a content of 500 pages converted twice would bury everything above.
        foreach (array_slice($files->deletions(), 0, self::DELETIONS_LISTED) as $path) {
            $this->line('    delete '.$this->remotePath($survey['site'], $path));
        }

        if (count($files->deletions()) > self::DELETIONS_LISTED) {
            $this->line(sprintf('    ... and %d more', count($files->deletions()) - self::DELETIONS_LISTED));
        }
    }

    /**
     * The plain answer to "would this add pages to a content that already has some". $writing is null
     * for a real conversion, where the page count is not known in advance.
     *
     * @param  array{profileId: int|null, storeMode: StoreMode, site: FtpSite, sources: list<SourceFile>, imagePages: list<SourceFile>}  $survey
     */
    private function compareWithArchive(array $survey, ?int $writing): void
    {
        $already = count($survey['imagePages']);

        $this->newLine();

        if ($already === 0) {
            $this->line($writing === null
                ? 'The content has no image page rows yet, so this conversion writes its first ones.'
                : sprintf('The content has no image page rows yet, so these %d page(s) would be its first.', $writing));

            return;
        }

        // Not a passing remark: a content that already has pages has been converted before, and the
        // pipeline's rollback deletes those rows (and their images) before it writes new ones. The
        // operator has to know that this run replaces a document rather than filling in a gap.
        $this->warn(sprintf('This content already has %d image page row(s), so it has been converted before.', $already));
        $this->warn($writing === null
            ? sprintf('The run first deletes the %d row(s) it has, and their images, then writes the pages it renders in their place.', $already)
            : sprintf('The run first deletes the %d row(s) it has, and their images, then writes %d new page(s) in their place.', $already, $writing));
    }

    /**
     * The queue row the pipeline needs, claimed for this command. Everything the pipeline records goes
     * through ConversionQueue, which refuses every update unless the row is claimed by the worker in
     * hand, so a run without one would report nothing at all.
     */
    private function claim(string $contentId, ?Conversion $queued, ?int $profileId): Conversion
    {
        $conversion = $queued ?? new Conversion(['content_id' => $contentId, 'profile_id' => $profileId]);

        $now = now();

        $conversion->forceFill([
            'status' => ConversionStatus::Claimed,
            'worker' => $this->worker(),
            'claimed_at' => $now,
            'heartbeat_at' => $now,
        ])->save();

        return $conversion;
    }

    /**
     * The content's queue row, if it has one. The lower-case fallback is for an operator who typed the
     * id in the other case: SQL Server compares GUIDs case-insensitively and MySQL compares the
     * column that way too, so an exact match that misses would otherwise try to insert a second row
     * for a content that is already queued.
     */
    private function queuedConversion(string $contentId): ?Conversion
    {
        return Conversion::query()->where('content_id', $contentId)->first()
            ?? Conversion::query()->whereRaw('lower(content_id) = ?', [strtolower($contentId)])->first();
    }

    /**
     * Where the conversion got to, read back from the row while it still exists.
     *
     * @return array{status: ConversionStatus, stage: string|null, reason: string|null, pages: int}
     */
    private function outcomeOf(Conversion $conversion): array
    {
        $conversion->refresh();

        return [
            'status' => $conversion->status,
            'stage' => $conversion->failure_stage?->label(),
            'reason' => $conversion->failure_reason,
            'pages' => (int) $conversion->pages,
        ];
    }

    /**
     * @param  array{status: ConversionStatus, stage: string|null, reason: string|null, pages: int}  $outcome
     */
    private function printOutcome(array $outcome, string $verb): int
    {
        $this->newLine();

        if ($outcome['status'] !== ConversionStatus::Done) {
            $this->error(sprintf(
                'The conversion did not reach the end: %s%s',
                $outcome['status']->label(),
                $outcome['stage'] === null ? '' : sprintf(' while %s - %s', $outcome['stage'], (string) $outcome['reason']),
            ));

            return self::FAILURE;
        }

        $this->info(sprintf('The conversion reached the end: %d page(s) %s.', $outcome['pages'], $verb));

        return self::SUCCESS;
    }

    /**
     * The path as it is on the server: the file store is given a path inside the site folder, and the
     * folder is what the operator sees in an FTP client.
     */
    private function remotePath(FtpSite $site, string $path): string
    {
        return trim($site->folder, '/').'/'.ltrim($path, '/');
    }

    private function bytes(int $bytes): string
    {
        return number_format($bytes).' bytes';
    }

    /**
     * @param  list<int>  $sizes
     */
    private function sizeRange(array $sizes): string
    {
        return min($sizes) === max($sizes)
            ? $this->bytes(min($sizes)).' each'
            : $this->bytes(min($sizes)).' to '.$this->bytes(max($sizes));
    }

    private function worker(): string
    {
        return substr(sprintf('try:%s:%d', gethostname() ?: 'unknown', getmypid() ?: 0), 0, 100);
    }

    /**
     * A rollback that cannot be performed must not replace the report with a database error: the run
     * itself wrote nothing outside this transaction, and a connection that has gone away has already
     * discarded it.
     */
    private function rollBackQuietly(ConnectionInterface $connection): void
    {
        try {
            $connection->rollBack();
        } catch (Throwable $exception) {
            report($exception);
            $this->warn('The temporary queue row could not be rolled back: '.$exception->getMessage());
        }
    }

    private function writesAreAllowed(): bool
    {
        return in_array(
            strtolower(trim((string) config('converter.archive.write_mode'))),
            self::WRITE_MODES_ON,
            true,
        );
    }
}
