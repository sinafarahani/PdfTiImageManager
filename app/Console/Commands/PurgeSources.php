<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Models\Conversion;
use App\Models\ConversionSource;
use App\Models\PurgedSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Destroys the source PDFs of contents that have been converted.
 *
 * This is the one irreversible thing the panel can do. Everywhere else the original is what makes a
 * mistake survivable: a conversion that came out wrong is undone with converters:undo and run again
 * from the same PDF. After this there is no PDF. The page images become the document, and if they are
 * wrong they stay wrong.
 *
 * So it refuses far more than it accepts. Four things have to hold, and each of them exists because
 * without it a real state of this archive loses a document:
 *
 *  - the conversion is Done, with pages recorded here;
 *  - the archive still holds at least as many page images, on show, as we recorded - not merely one.
 *    An interrupted converters:undo leaves a Done row whose pages are half deleted, and "at least one
 *    page row exists" would happily strip the source off a document being taken apart;
 *  - the source row is one the conversion hid ITSELF (conversion_sources). A content's MVDContent can
 *    hold PDF rows an archive user deleted in the viewer years ago - never rendered, no pages, and
 *    recoverable by unsetting one flag - and hiddenSourcesFor() cannot tell those from ours;
 *  - the archive is accepting writes, checked before anything is recorded rather than discovered
 *    halfway through.
 *
 * Contents converted before the panel began recording its sources have no ledger to check, so they
 * are refused unless --unrecorded is given, and even then only when the content has exactly one
 * hidden PDF row - in which case it is provably the one the conversion hid, because a successful
 * conversion always hides its own.
 *
 * The order within one source is row, then file, and not the other way round. A failure between them
 * has to leave the recoverable half: a row deleted with the file still there is recorded here with
 * its full path and finished by the next run, while a file deleted with the row still there is a
 * document the archive still advertises and nothing can bring back.
 */
class PurgeSources extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:purge-sources
        {--content=* : only these content ids}
        {--limit=0 : most contents in one run, or 0 for every one of them}
        {--check=500 : contents a rehearsal asks the archive about}
        {--unrecorded : allow contents converted before the panel recorded its sources}
        {--all-hidden : with --unrecorded, allow contents that have more than one hidden PDF row}
        {--confirm : actually destroy them}';

    /**
     * @var string
     */
    protected $description = 'Delete the source PDFs of converted contents, for good';

    private int $rowsDestroyed = 0;

    private int $filesDeleted = 0;

    private int $contents = 0;

    private int $refused = 0;

    private int $orphaned = 0;

    /**
     * Why contents were left alone, counted per reason.
     *
     * Grouped rather than printed one by one: a first run against a queue converted before any of
     * this existed refuses every content for the same reason, and five hundred identical warnings
     * say less than one line with a number in front of it.
     *
     * @var array<string, array{count: int, example: string}>
     */
    private array $refusals = [];

    public function handle(ArchiveGateway $archive, FileStore $store): int
    {
        $confirmed = (bool) $this->option('confirm');

        // Before anything is written down, let alone destroyed. Discovering this halfway through used
        // to leave a record claiming a content was purged when nothing had been, which then hid that
        // content from every later run.
        if ($confirmed && ! $this->writesAreOn()) {
            $this->components->error('The archive is not accepting writes, so nothing was touched.');
            $this->line('  Set CONVERTER_WRITE_MODE=on in .env to let this run.');

            return self::FAILURE;
        }

        $this->finishInterrupted($archive, $store, $confirmed);

        $total = $this->eligible()->count();

        if ($total === 0) {
            $this->components->info('No converted content has a source PDF left to delete.');

            return self::SUCCESS;
        }

        if (! $confirmed) {
            $this->rehearse($archive, $total);

            return self::SUCCESS;
        }

        $limit = max(0, (int) $this->option('limit'));
        $wanted = $limit === 0 ? $total : min($limit, $total);

        $this->line(sprintf('Destroying the source PDF of %s content(s).', number_format($wanted)));
        $bar = $this->output->createProgressBar($wanted);
        $bar->start();

        $seen = 0;
        $stopped = false;

        // Chunked by key rather than loaded in one go: this runs over every converted content in the
        // archive, and there are tens of thousands of them. Each chunk re-reads the table, and a
        // content purged in an earlier chunk has dropped out of the query by then.
        $this->eligible()->chunkById(200, function (Collection $chunk) use ($archive, $store, $bar, $wanted, &$seen, &$stopped): bool {
            foreach ($chunk as $conversion) {
                if ($seen >= $wanted) {
                    return false;
                }

                try {
                    $this->purge($archive, $store, $conversion);
                } catch (Throwable $exception) {
                    // One content that cannot be finished must not take the run down. Whatever was
                    // destroyed is recorded, and finishInterrupted() picks the rest up next time.
                    report($exception);

                    $this->newLine();
                    $this->components->error("{$conversion->content_id}: {$exception->getMessage()}");
                    $this->refused++;
                    $stopped = true;

                    return false;
                }

                $seen++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine();

        $this->report();

        if ($stopped) {
            $this->components->warn('The run stopped early. Run it again to carry on from where it left off.');
        }

        return self::SUCCESS;
    }

    /**
     * Counted apart, because they fail apart. The archive row is destroyed first and irreversibly;
     * the file delete can fail on its own and leave the file behind, and a run that says "0
     * destroyed" when it has deleted five hundred archive rows is the report of an incident.
     */
    private function report(): void
    {
        $this->newLine();
        $this->components->info(sprintf(
            '%s archive row(s) destroyed across %s content(s); %s file(s) deleted.',
            number_format($this->rowsDestroyed),
            number_format($this->contents),
            number_format($this->filesDeleted),
        ));

        if ($this->orphaned > 0) {
            $this->components->warn(sprintf(
                '%s file(s) could not be deleted and are still on the store. Their rows are gone; run this again to finish them.',
                number_format($this->orphaned),
            ));
        }

        if ($this->refused > 0) {
            $this->components->warn(sprintf('%s content(s) stopped the run. Nothing about them was changed.', number_format($this->refused)));
        }

        $this->newLine();
        $this->reportRefusals();
    }

    /**
     * Contents this panel converted whose source has not been purged yet, oldest first.
     *
     * @return \Illuminate\Database\Eloquent\Builder<Conversion>
     */
    private function eligible()
    {
        $query = Conversion::query()
            ->where('status', ConversionStatus::Done)

            // Converted with pages we recorded ourselves. A done conversion with no pages is one of
            // the states the old pipeline used to leave behind, and its source is all there is.
            ->where('pages', '>', 0)
            ->whereNotExists(function (Builder $purged): void {
                $purged->select(DB::raw(1))
                    ->from('purged_sources')
                    ->whereColumn('purged_sources.content_id', 'conversions.content_id');
            })
            ->orderBy('finished_at');

        if (($contents = (array) $this->option('content')) !== []) {
            // Both cases, because the archive's ids are upper case GUIDs and a person types them
            // either way. Matching on one form only happens to work on MySQL, whose collation is
            // case-insensitive, and silently matches nothing anywhere else.
            $wanted = [];

            foreach ($contents as $contentId) {
                $contentId = trim((string) $contentId);
                $wanted[] = strtoupper($contentId);
                $wanted[] = strtolower($contentId);
            }

            $query->whereIn('content_id', array_values(array_unique($wanted)));
        }

        return $query;
    }

    /**
     * Says what would happen, checking every content against the archive as it goes, so that a
     * rehearsal is a real answer rather than a count of rows.
     *
     * @param  Collection<int, Conversion>  $eligible
     */
    private function rehearse(ArchiveGateway $archive, int $total): void
    {
        // Every content checked costs two questions to the archive, and there can be tens of
        // thousands of them, so a rehearsal checks a sample and is honest about having done so. The
        // total comes from one count and is the number that matters.
        $check = max(1, (int) $this->option('check'));
        $checked = min($check, $total);
        $ready = 0;
        $shown = 0;

        foreach ($this->eligible()->limit($checked)->get() as $conversion) {
            $sources = $this->destroyable($archive, $conversion);

            if ($sources === []) {
                continue;
            }

            $ready++;

            foreach ($sources as $source) {
                if ($shown++ < 10) {
                    $this->line(sprintf('    %s  %s/%s', $conversion->content_id, $source->remoteFolder(), $source->remoteFileName()));
                }
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=yellow>converted contents whose source is still there</>', '<fg=yellow>'.number_format($total).'</>');
        $this->components->twoColumnDetail('of those, asked the archive about', number_format($checked).($checked < $total ? '  (--check raises this)' : ''));
        $this->components->twoColumnDetail('of those, ready to be destroyed', number_format($ready));

        $this->newLine();

        if ($ready > 0) {
            $limit = max(0, (int) $this->option('limit'));

            $this->components->warn(sprintf(
                'A --confirm run would destroy the source PDF of %s content(s). There is no way back from this.',
                $limit === 0 ? number_format($total) : number_format(min($limit, $total)),
            ));

            if ($checked < $total) {
                $this->line(sprintf('  Only %s were checked, so the rest may include some it would refuse.', number_format($checked)));
            }

            $this->line('  Run it again with --confirm to do it.');
        } else {
            $this->components->info('Nothing would be destroyed.');
        }

        $this->newLine();
        $this->reportRefusals();
    }

    /**
     * One content.
     */
    private function purge(ArchiveGateway $archive, FileStore $store, Conversion $conversion): void
    {
        $sources = $this->destroyable($archive, $conversion);

        if ($sources === []) {
            return;
        }

        $destroyed = 0;

        foreach ($sources as $source) {
            $destroyed += $this->destroy($archive, $store, $conversion, $source) ? 1 : 0;
        }

        if ($destroyed > 0) {
            $this->contents++;
        }
    }

    /**
     * The source rows of this content that may be destroyed, or none at all.
     *
     * Everything that decides whether a document survives is here, in one place, and both the
     * rehearsal and the real run ask exactly this question.
     *
     * @return list<SourceFile>
     */
    private function destroyable(ArchiveGateway $archive, Conversion $conversion): array
    {
        $hidden = $archive->hiddenSourcesFor($conversion->content_id);

        if ($hidden === []) {
            $this->refuse(
                'the archive has no hidden PDF row for this content, so there is no converted source to delete',
                $conversion->content_id,
            );

            return [];
        }

        // The pages have to be there, and there have to be as many as we recorded. "At least one page
        // row exists" passes on a document that is halfway through being taken apart by an
        // interrupted converters:undo.
        $live = $archive->livePageIdsFor($conversion->content_id);
        $recorded = (int) $conversion->pages;

        if (count($live) < $recorded) {
            $this->refuse(
                'fewer page images are on show in the archive than the conversion recorded',
                sprintf('%s (%d on show, %d recorded)', $conversion->content_id, count($live), $recorded),
            );

            return [];
        }

        // Every page row the panel wrote must still be one of them. The ledger exists precisely so
        // that this can be exact rather than a count.
        $ours = $conversion->pages()->whereNotNull('mvd_id')->pluck('mvd_id')->all();
        $missing = array_diff($ours, $live);

        if ($missing !== []) {
            $this->refuse(
                'page rows this conversion wrote are no longer in the archive',
                sprintf('%s (%d of %d gone)', $conversion->content_id, count($missing), count($ours)),
            );

            return [];
        }

        return $this->onlyOurs($conversion, $hidden);
    }

    /**
     * Narrows the content's hidden PDF rows to the ones this conversion hid itself.
     *
     * @param  list<SourceFile>  $hidden
     * @return list<SourceFile>
     */
    private function onlyOurs(Conversion $conversion, array $hidden): array
    {
        $recorded = ConversionSource::query()
            ->where('content_id', $conversion->content_id)
            ->pluck('mvd_id')
            ->all();

        if ($recorded !== []) {
            $ours = array_values(array_filter(
                $hidden,
                fn (SourceFile $source): bool => in_array($source->mvdId, $recorded, true),
            ));

            if ($ours === []) {
                $this->refuse(
                    'the source rows this conversion hid are no longer in the archive',
                    $conversion->content_id,
                );
            }

            return $ours;
        }

        // Converted before the panel started recording its sources. One hidden PDF row is provably
        // the one the conversion hid, because a conversion always hides its own; more than one and
        // there is no way to tell ours from a revision somebody deleted in the viewer years ago.
        if (! $this->option('unrecorded')) {
            $this->refuse(self::NOT_RECORDED, $conversion->content_id);

            return [];
        }

        if (count($hidden) !== 1 && ! $this->option('all-hidden')) {
            // One hidden PDF row is provably the one the conversion hid. Several are not: the
            // pipeline converts every PDF a content has on show, so they may all be ours - or one of
            // them may be a revision somebody deleted in the viewer years ago, which was never
            // rendered and still has no pages. Nothing in the archive distinguishes them, so this
            // needs saying out loud rather than assuming.
            $this->refuse(
                'several hidden PDF rows and no record of which one was converted; --all-hidden takes them all',
                sprintf('%s (%d hidden PDF rows)', $conversion->content_id, count($hidden)),
            );

            return [];
        }

        return $hidden;
    }

    /**
     * Records why one content was left alone. $reason is the shared explanation; $example names the
     * content, so a reason that applies to one row can still be chased.
     */
    private function refuse(string $reason, string $example): void
    {
        $this->refusals[$reason] ??= ['count' => 0, 'example' => $example];
        $this->refusals[$reason]['count']++;
    }

    /**
     * The reasons, commonest first. This is the part that turns "0 would be destroyed" from a dead
     * end into something to act on.
     */
    private function reportRefusals(): void
    {
        if ($this->refusals === []) {
            return;
        }

        uasort($this->refusals, fn (array $a, array $b): int => $b['count'] <=> $a['count']);

        $total = array_sum(array_column($this->refusals, 'count'));

        $this->line(sprintf('  %s content(s) were left alone:', number_format($total)));

        foreach ($this->refusals as $reason => $refusal) {
            $this->line(sprintf('      %6s  %s', number_format($refusal['count']), $reason));
            $this->line(sprintf('              e.g. %s', $refusal['example']));
        }

        if (isset($this->refusals[self::NOT_RECORDED]) && ! $this->option('unrecorded')) {
            $this->newLine();
            $this->line('  Those were converted before the panel began recording which source row it hid.');
            $this->line('  --unrecorded allows them, and only where the content has exactly one hidden PDF row,');
            $this->line('  which is then provably the one the conversion hid.');
        }

        foreach (array_keys($this->refusals) as $reason) {
            if (str_contains((string) $reason, '--all-hidden')) {
                $this->newLine();
                $this->line('  Those contents have more than one hidden PDF row and no record of which was converted.');
                $this->line('  The pipeline converts every PDF a content has on show, so they are probably all its');
                $this->line('  sources - but a PDF deleted in the viewer before the conversion looks identical, and');
                $this->line('  that one was never rendered and has no pages to replace it. --all-hidden destroys them all.');

                break;
            }
        }
    }

    /**
     * The one refusal that has a remedy, so it is worth naming.
     */
    private const NOT_RECORDED = 'converted before the panel recorded which source row it hid';

    /**
     * Record, then the archive row, then the file. Returns whether the row was destroyed.
     */
    private function destroy(ArchiveGateway $archive, FileStore $store, Conversion $conversion, SourceFile $source): bool
    {
        $remotePath = $source->remoteFolder().'/'.$source->remoteFileName();

        // Everything that will be unrecoverable in a moment, written down while it still exists.
        $record = PurgedSource::query()->create([
            'content_id' => $conversion->content_id,
            'mvd_id' => $source->mvdId,
            'conversion_id' => $conversion->id,
            'remote_path' => $remotePath,
            'original_name' => $source->pageNo,
            'create_date_time' => $source->createDateTime,
            'bytes' => $this->sizeOf($store, $remotePath),
        ]);

        try {
            $deleted = $archive->hardDeleteSource($source->mvdId);
        } catch (Throwable $exception) {
            // Nothing was destroyed, so the record must not survive to claim otherwise - it would
            // hide this content from every later run.
            $record->delete();

            throw $exception;
        }

        if (! $deleted) {
            // The statement guards on Deleted = 1 and a PDF format, so a miss means this row is not
            // what we think it is. Nothing was deleted and the record says so by not existing.
            $this->components->warn("{$conversion->content_id}: MVDContent {$source->mvdId} is not a hidden PDF row; left alone.");
            $record->delete();
            $this->refused++;

            return false;
        }

        $record->update(['row_deleted' => true]);
        $this->rowsDestroyed++;

        $this->deleteFile($store, $record);

        return true;
    }

    /**
     * Files whose row is already gone but whose delete did not finish.
     *
     * It looks at every record whose file is still down as present, whatever row_deleted says,
     * because the flag is written after the archive delete and a process that died in between leaves
     * a record that understates what happened. The archive is asked about the row before anything is
     * deleted, so a record that never got past being written cannot make this delete a live file.
     */
    private function finishInterrupted(ArchiveGateway $archive, FileStore $store, bool $confirmed): void
    {
        $unfinished = PurgedSource::query()
            ->where('file_deleted', false)
            ->limit(1000)
            ->get();

        if ($unfinished->isEmpty()) {
            return;
        }

        if (! $confirmed) {
            $this->components->warn(sprintf(
                '%d file(s) from an earlier run are still on the file store; --confirm finishes them.',
                $unfinished->count(),
            ));

            return;
        }

        foreach ($unfinished as $record) {
            // Asked of the row itself, ignoring Deleted: hiddenSourcesFor() would read a source that
            // converters:undo has since put back on show as "gone" and delete a live document's file.
            if ($archive->sourceRowExists($record->mvd_id)) {
                $this->components->warn("{$record->remote_path}: its archive row is still there, so the file was left alone.");

                continue;
            }

            $record->update(['row_deleted' => true]);
            $this->deleteFile($store, $record);
        }
    }

    private function deleteFile(FileStore $store, PurgedSource $record): void
    {
        try {
            $store->delete($record->remote_path);
        } catch (FileStoreException $exception) {
            // The row is already gone, so nothing points at this file any more. Left recorded as
            // unfinished so the next run tries again.
            $this->components->warn("{$record->remote_path}: {$exception->getMessage()}");
            $this->orphaned++;

            return;
        }

        $record->update(['file_deleted' => true]);
        $this->filesDeleted++;
    }

    private function writesAreOn(): bool
    {
        return in_array(
            strtolower(trim((string) config('converter.archive.write_mode'))),
            ['on', 'true', '1', 'yes', 'enabled'],
            true,
        );
    }

    private function sizeOf(FileStore $store, string $remotePath): ?int
    {
        try {
            return $store->size($remotePath);
        } catch (FileStoreException) {
            return null;
        }
    }
}
