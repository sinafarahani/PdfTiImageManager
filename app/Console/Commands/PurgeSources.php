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
        {--limit=500 : most contents in one run}
        {--unrecorded : allow contents converted before the panel recorded its sources}
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

        $eligible = $this->eligible();

        if ($eligible->isEmpty()) {
            $this->components->info('No converted content has a source PDF left to delete.');

            return self::SUCCESS;
        }

        if (! $confirmed) {
            $this->rehearse($archive, $eligible);

            return self::SUCCESS;
        }

        foreach ($eligible as $conversion) {
            try {
                $this->purge($archive, $store, $conversion);
            } catch (Throwable $exception) {
                // One content that cannot be finished must not take the run down. Whatever was
                // destroyed is recorded, and finishInterrupted() picks the rest up next time.
                report($exception);

                $this->components->error("{$conversion->content_id}: {$exception->getMessage()}");
                $this->refused++;

                break;
            }
        }

        $this->report();

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
            $this->components->warn(sprintf('%s content(s) were left alone. Nothing about them was changed.', number_format($this->refused)));
        }
    }

    /**
     * Contents this panel converted whose source has not been purged yet, oldest first.
     *
     * @return Collection<int, Conversion>
     */
    private function eligible(): Collection
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

        return $query->limit(max(1, (int) $this->option('limit')))->get();
    }

    /**
     * Says what would happen, checking every content against the archive as it goes, so that a
     * rehearsal is a real answer rather than a count of rows.
     *
     * @param  Collection<int, Conversion>  $eligible
     */
    private function rehearse(ArchiveGateway $archive, Collection $eligible): void
    {
        $ready = 0;
        $shown = 0;

        foreach ($eligible as $conversion) {
            $sources = $this->destroyable($archive, $conversion, quiet: true);

            if ($sources === []) {
                $this->refused++;

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
        $this->components->warn(sprintf(
            '%s content(s) would have their source PDF destroyed. There is no way back from this.',
            number_format($ready),
        ));

        if ($this->refused > 0) {
            $this->line(sprintf('  %s content(s) would be left alone.', number_format($this->refused)));
        }

        $this->line('  Run it again with --confirm to do it.');
    }

    /**
     * One content.
     */
    private function purge(ArchiveGateway $archive, FileStore $store, Conversion $conversion): void
    {
        $sources = $this->destroyable($archive, $conversion);

        if ($sources === []) {
            $this->refused++;

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
    private function destroyable(ArchiveGateway $archive, Conversion $conversion, bool $quiet = false): array
    {
        $hidden = $archive->hiddenSourcesFor($conversion->content_id);

        if ($hidden === []) {
            return [];
        }

        // The pages have to be there, and there have to be as many as we recorded. "At least one page
        // row exists" passes on a document that is halfway through being taken apart by an
        // interrupted converters:undo.
        $live = $archive->livePageIdsFor($conversion->content_id);
        $recorded = (int) $conversion->pages;

        if (count($live) < $recorded) {
            $this->refuse($quiet, sprintf(
                '%s: the archive shows %d page image(s) and this conversion recorded %d, so its source PDF was left alone.',
                $conversion->content_id,
                count($live),
                $recorded,
            ));

            return [];
        }

        // Every page row the panel wrote must still be one of them. The ledger exists precisely so
        // that this can be exact rather than a count.
        $ours = $conversion->pages()->whereNotNull('mvd_id')->pluck('mvd_id')->all();
        $missing = array_diff($ours, $live);

        if ($missing !== []) {
            $this->refuse($quiet, sprintf(
                '%s: %d page row(s) this conversion wrote are no longer in the archive, so its source PDF was left alone.',
                $conversion->content_id,
                count($missing),
            ));

            return [];
        }

        return $this->onlyOurs($conversion, $hidden, $quiet);
    }

    /**
     * Narrows the content's hidden PDF rows to the ones this conversion hid itself.
     *
     * @param  list<SourceFile>  $hidden
     * @return list<SourceFile>
     */
    private function onlyOurs(Conversion $conversion, array $hidden, bool $quiet): array
    {
        $recorded = ConversionSource::query()
            ->where('content_id', $conversion->content_id)
            ->pluck('mvd_id')
            ->all();

        if ($recorded !== []) {
            return array_values(array_filter(
                $hidden,
                fn (SourceFile $source): bool => in_array($source->mvdId, $recorded, true),
            ));
        }

        // Converted before the panel started recording its sources. One hidden PDF row is provably
        // the one the conversion hid, because a conversion always hides its own; more than one and
        // there is no way to tell ours from a revision somebody deleted in the viewer years ago.
        if (! $this->option('unrecorded')) {
            $this->refuse($quiet, sprintf(
                '%s: converted before the panel recorded which source it hid; --unrecorded allows it.',
                $conversion->content_id,
            ));

            return [];
        }

        if (count($hidden) !== 1) {
            $this->refuse($quiet, sprintf(
                '%s: has %d hidden PDF row(s) and no record of which one it converted, so none of them were touched.',
                $conversion->content_id,
                count($hidden),
            ));

            return [];
        }

        return $hidden;
    }

    private function refuse(bool $quiet, string $message): void
    {
        if (! $quiet) {
            $this->components->warn($message);
        }
    }

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
