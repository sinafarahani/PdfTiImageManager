<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Models\Conversion;
use App\Models\ConversionPage;
use App\Models\PurgedSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Takes one content's conversion back out of the archive: its page rows, their images on the file
 * store, the flag that hid its source PDF, and the verdict on the content itself.
 *
 * This is the answer to "the canary looks wrong in the viewer". It is exact rather than a guess,
 * because every page row and every upload was recorded in conversion_pages before it was created -
 * and it also finds page rows this panel did not write, so a content the previous pipeline half
 * converted can be cleaned up the same way.
 *
 * What it deliberately does not do is decide what happens next: the conversion is left cancelled, and
 * --requeue is how you ask for it to be converted again.
 */
class UndoContent extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:undo
        {contentId : the GeneralContent id}
        {--confirm : actually do it}
        {--requeue : queue the content to be converted again afterwards}';

    /**
     * @var string
     */
    protected $description = "Remove a content's converted pages from the archive and put its source back";

    public function handle(ArchiveGateway $archive, FileStore $files): int
    {
        $contentId = trim((string) $this->argument('contentId'));
        $conversion = $this->conversionFor($contentId);

        if ($conversion?->status === ConversionStatus::Claimed) {
            $this->components->error('A worker is converting this content right now.');
            $this->line('  Wait for it to finish, or run converters:reconcile if its worker is gone.');

            return self::FAILURE;
        }

        // An undo puts the pages back into the source PDF they came from. If that PDF has been
        // destroyed by converters:purge-sources there is nothing to go back to, and carrying on would
        // delete the page images that ARE the document now and leave the content with neither.
        if (($purged = PurgedSource::query()->where('content_id', $contentId)->get())->isNotEmpty()) {
            $this->components->error('This content\'s source PDF was destroyed by converters:purge-sources, so there is nothing to put back.');

            foreach ($purged as $record) {
                $this->line(sprintf(
                    '  %s (%s) was deleted from %s on %s.',
                    $record->original_name ?: $record->mvd_id,
                    $record->mvd_id,
                    $record->remote_path,
                    $record->created_at?->toDayDateTimeString() ?? 'an unknown date',
                ));
            }

            $this->line('  Undoing would delete the page images as well, and they are the only copy of this document now.');

            return self::FAILURE;
        }

        $pages = $archive->imagePagesFor($contentId);
        $recorded = $conversion?->pages()->get() ?? new Collection;
        $sources = $archive->hiddenSourcesFor($contentId);

        $this->report($contentId, $conversion, $pages, $recorded, $sources);

        if ($pages === [] && $recorded->isEmpty() && $sources === []) {
            $this->components->info('There is nothing to undo for this content.');

            return self::SUCCESS;
        }

        if (! $this->option('confirm')) {
            $this->components->warn('Nothing was changed. Run it again with --confirm to do it.');

            return self::SUCCESS;
        }

        if (! $this->writesAllowed()) {
            $this->components->error('CONVERTER_WRITE_MODE is not "on", so the archive refuses every write.');
            $this->line('  Set CONVERTER_WRITE_MODE=on in .env, run php artisan optimize, and try again.');

            return self::FAILURE;
        }

        return $this->undo($archive, $files, $contentId, $conversion, $pages, $recorded, $sources);
    }

    /**
     * @param  list<SourceFile>  $pages
     * @param  Collection<int, ConversionPage>  $recorded
     * @param  list<SourceFile>  $sources
     */
    private function undo(
        ArchiveGateway $archive,
        FileStore $files,
        string $contentId,
        ?Conversion $conversion,
        array $pages,
        $recorded,
        array $sources,
    ): int {
        // The images go first. A page row without its image is a visible fault that the next
        // conversion fixes; an image without its row is a file nothing will ever find again.
        $deleted = 0;
        $missed = 0;

        foreach ($this->remotePathsOf($pages, $recorded) as $path) {
            try {
                $files->delete($path);
                $deleted++;
            } catch (FileStoreException $exception) {
                $missed++;
                $this->line("  could not delete {$path}: {$exception->getMessage()}");
            }
        }

        $this->components->twoColumnDetail('images deleted from the file store', (string) $deleted.($missed > 0 ? " ({$missed} could not be deleted)" : ''));

        $mvdIds = array_values(array_unique(array_merge(
            array_map(fn (SourceFile $page): string => $page->mvdId, $pages),
            $recorded->pluck('mvd_id')->filter()->all(),
        )));

        if ($mvdIds !== []) {
            $archive->deletePages($mvdIds);
            $this->components->twoColumnDetail('page rows removed from the archive', (string) count($mvdIds).' (their thumbnails and images follow)');
        }

        foreach ($sources as $source) {
            $archive->restoreSource($source->mvdId);
        }

        if ($sources !== []) {
            $this->components->twoColumnDetail('source PDF rows put back on show', (string) count($sources));
        }

        $archive->undoConverted($contentId);
        $this->components->twoColumnDetail('the content itself', 'back to not converted, and free for discovery');

        $conversion?->pages()->delete();
        $conversion?->forceFill([
            'status' => $this->option('requeue') ? ConversionStatus::Pending : ConversionStatus::Cancelled,
            'attempts' => 0,
            'pages' => null,
            'failure_stage' => null,
            'failure_reason' => null,
            'worker' => null,
            'claimed_at' => null,
            'heartbeat_at' => null,
            'finished_at' => $this->option('requeue') ? null : now(),
        ])->save();

        $this->components->twoColumnDetail(
            'the conversion in the panel',
            $this->option('requeue') ? 'queued again' : 'cancelled (use converters:undo --requeue, or converters:retry, to convert it again)',
        );

        return self::SUCCESS;
    }

    /**
     * Every image that has to go: the ones this panel recorded uploading, and the ones it can work out
     * from the archive's own rows, because a page row names its file through its id and its timestamp.
     *
     * @param  list<SourceFile>  $pages
     * @param  Collection<int, ConversionPage>  $recorded
     * @return list<string>
     */
    private function remotePathsOf(array $pages, $recorded): array
    {
        $paths = $recorded->pluck('remote_path')->filter()->all();

        foreach ($pages as $page) {
            $paths[] = $page->remoteFolder().'/'.$page->remoteFileName();
        }

        return array_values(array_unique($paths));
    }

    /**
     * @param  list<SourceFile>  $pages
     * @param  Collection<int, ConversionPage>  $recorded
     * @param  list<SourceFile>  $sources
     */
    private function report(string $contentId, ?Conversion $conversion, array $pages, $recorded, array $sources): void
    {
        $this->line("Undo of content {$contentId}");
        $this->newLine();

        $this->components->twoColumnDetail('in the panel', $conversion === null
            ? 'no conversion row'
            : sprintf('%s, %s page(s) recorded, %d attempt(s)', $conversion->status->value, $recorded->count(), $conversion->attempts));

        $this->components->twoColumnDetail('image page rows in the archive', (string) count($pages));

        foreach (array_slice($pages, 0, 10) as $page) {
            $this->line(sprintf('    %s  seq %-4s  %s/%s', $page->mvdId, $page->seqPageNo ?? '?', $page->remoteFolder(), $page->remoteFileName()));
        }

        if (count($pages) > 10) {
            $this->line('    ... and '.(count($pages) - 10).' more');
        }

        $this->components->twoColumnDetail('hidden source PDF rows', (string) count($sources));

        foreach ($sources as $source) {
            $this->line(sprintf('    %s  "%s"', $source->mvdId, trim($source->pageNo)));
        }

        $this->newLine();
    }

    private function conversionFor(string $contentId): ?Conversion
    {
        return Conversion::query()->where('content_id', $contentId)->first()
            ?? Conversion::query()->whereRaw('lower(content_id) = ?', [strtolower($contentId)])->first();
    }

    private function writesAllowed(): bool
    {
        return in_array(strtolower(trim((string) config('converter.archive.write_mode'))), ['on', 'true', '1', 'yes', 'enabled'], true);
    }
}
