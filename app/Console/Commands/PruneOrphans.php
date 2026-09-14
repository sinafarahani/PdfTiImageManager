<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Models\Conversion;
use App\Models\PurgedSource;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

/**
 * Removes MVDContent source rows whose PDF file is not on the file store any more.
 *
 * These are what was left when files were deleted by hand to free disk space: the file went, the row
 * stayed, and the archive still advertises a document nobody can open. The same thing happened
 * wholesale to the profiles named in CONVERTER_SKIP_PROFILES.
 *
 * It reads very differently from converters:purge-sources, and the difference is the point. That
 * command destroys a source that is still there, and the document with it. This one destroys
 * nothing: the document was lost whenever the file was deleted, and what is removed is the pointer
 * that outlived it. So it does not ask whether the pages exist or which conversion hid the row -
 * there is nothing left to protect.
 *
 * What it does have to be certain of is that the file is really gone rather than briefly unreachable.
 * A store that is down makes every file look deleted, and a run that believed it would strip the
 * archive of every source row it could reach. So: the store is probed before the run starts and
 * again as it goes, a file is only ever called missing when the server actually answered and said
 * so, and anything that merely failed is left alone.
 *
 * Only rows the archive has already flagged Deleted = 1 are touched. A source still on show belongs
 * to a document the archive is still offering, and a missing file there is a fault to investigate
 * rather than a row to tidy away - those are counted and reported, never deleted.
 */
class PruneOrphans extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:prune-orphans
        {--content=* : only these content ids}
        {--limit=0 : most contents in one run, or 0 for every one of them}
        {--check=500 : contents a rehearsal examines, or 0 for every one of them}
        {--confirm : actually delete the rows}';

    /**
     * @var string
     */
    protected $description = 'Remove archive source rows whose PDF file was deleted long ago';

    /**
     * Contents between one health probe of the file store and the next. A store that goes down
     * mid-run must not be able to turn the rest of the run into a sweep of good rows.
     */
    private const PROBE_EVERY = 250;

    private int $rowsDeleted = 0;

    private int $filesStillThere = 0;

    private int $unreadable = 0;

    private int $onShow = 0;

    private int $checked = 0;

    public function handle(ArchiveGateway $archive, FileStore $store): int
    {
        $confirmed = (bool) $this->option('confirm');

        if ($confirmed && ! $this->writesAreOn()) {
            $this->components->error('The archive is not accepting writes, so nothing was touched.');
            $this->line('  Set CONVERTER_WRITE_MODE=on in .env to let this run.');

            return self::FAILURE;
        }

        if (! $this->storeIsUp($store)) {
            return self::FAILURE;
        }

        $total = $this->candidates()->count();

        if ($total === 0) {
            $this->components->info('There are no contents to look at.');

            return self::SUCCESS;
        }

        // 0 means all, for both, so "look at everything" is the same answer to either question.
        $limit = max(0, (int) $this->option('limit'));
        $check = max(0, (int) $this->option('check'));

        $wanted = $confirmed
            ? ($limit === 0 ? $total : min($limit, $total))
            : ($check === 0 ? $total : min($check, $total));

        $this->line(sprintf(
            '%s %s of %s content(s) for source rows whose file is gone.%s',
            $confirmed ? 'Checking' : 'Rehearsing over',
            number_format($wanted),
            number_format($total),
            $wanted < $total && ! $confirmed ? '  (--check raises this)' : '',
        ));

        $bar = $this->output->createProgressBar($wanted);
        $bar->start();

        $stopped = false;

        $this->candidates()->chunkById(200, function (Collection $chunk) use ($archive, $store, $bar, $wanted, $confirmed, &$stopped): bool {
            foreach ($chunk as $conversion) {
                if ($this->checked >= $wanted) {
                    return false;
                }

                // The store is asked again every so often. Being told "no such file" thousands of
                // times is exactly what a legitimate run looks like AND what a store that has gone
                // away looks like, and only the store itself can tell the two apart.
                if ($this->checked > 0 && $this->checked % self::PROBE_EVERY === 0 && ! $this->storeIsUp($store, quiet: true)) {
                    $this->newLine();
                    $this->components->error('The file store stopped answering, so the run was stopped before it could mistake that for deleted files.');
                    $stopped = true;

                    return false;
                }

                try {
                    $this->examine($archive, $store, $conversion, $confirmed);
                } catch (Throwable $exception) {
                    report($exception);

                    $this->newLine();
                    $this->components->error("{$conversion->content_id}: {$exception->getMessage()}");
                    $stopped = true;

                    return false;
                }

                $this->checked++;
                $bar->advance();
            }

            return true;
        });

        $bar->finish();
        $this->newLine();
        $this->report($confirmed, $total);

        if ($stopped) {
            $this->components->warn('The run stopped early. Run it again to carry on from where it left off.');
        }

        return self::SUCCESS;
    }

    /**
     * Every content the panel knows of, whatever became of it.
     *
     * Deliberately not just the converted ones: a content of a skipped profile was never converted
     * and its file was deleted wholesale, and one whose source went missing before it could be
     * converted is in the same state. They all leave the same orphan behind.
     *
     * @return Builder<Conversion>
     */
    private function candidates()
    {
        $query = Conversion::query()->orderBy('id');

        if (($contents = (array) $this->option('content')) !== []) {
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
     * One content's hidden source rows.
     */
    private function examine(ArchiveGateway $archive, FileStore $store, Conversion $conversion, bool $confirmed): void
    {
        foreach ($archive->hiddenSourcesFor($conversion->content_id) as $source) {
            $remotePath = $source->remoteFolder().'/'.$source->remoteFileName();

            try {
                $size = $store->size($remotePath);
            } catch (FileStoreException) {
                // The store did not answer for this one. Not evidence of anything, so it is left
                // alone and counted; the next run asks again.
                $this->unreadable++;

                continue;
            }

            if ($size !== null) {
                $this->filesStillThere++;

                continue;
            }

            if (! $confirmed) {
                if ($this->rowsDeleted < 10) {
                    $this->newLine();
                    $this->line(sprintf('    %s  %s', $conversion->content_id, $remotePath));
                }

                $this->rowsDeleted++;

                continue;
            }

            $this->remove($archive, $conversion, $source, $remotePath);
        }

        if (! $confirmed) {
            $this->countSourcesStillOnShow($archive, $store, $conversion);
        }
    }

    /**
     * Counts the content's sources that are missing their file but are NOT flagged deleted.
     *
     * A different population and not this command's to touch: the archive is still offering those
     * documents, so a missing file there is a fault to look into rather than a row to tidy away. It
     * is also where the skipped profiles mostly sit - their files were deleted wholesale but the
     * pipeline never converted them, so nothing ever flagged their rows.
     *
     * Only counted while rehearsing, since nothing is done about them either way and it doubles the
     * questions asked of the file store.
     */
    private function countSourcesStillOnShow(ArchiveGateway $archive, FileStore $store, Conversion $conversion): void
    {
        foreach ($archive->sourceFilesFor($conversion->content_id) as $source) {
            if (! $source->isPdf()) {
                continue;
            }

            try {
                if ($store->size($source->remoteFolder().'/'.$source->remoteFileName()) === null) {
                    $this->onShow++;
                }
            } catch (FileStoreException) {
                // Says nothing either way, and nothing is done about these regardless.
            }
        }
    }

    private function remove(ArchiveGateway $archive, Conversion $conversion, SourceFile $source, string $remotePath): void
    {
        // Written down first, as with a purge. It is a smaller loss - the file was already gone - but
        // it is still the last record that the archive ever had a row for this document.
        $record = PurgedSource::query()->updateOrCreate(['mvd_id' => $source->mvdId], [
            'content_id' => $conversion->content_id,
            'reason' => PurgedSource::ORPHANED,
            'conversion_id' => $conversion->id,
            'remote_path' => $remotePath,
            'original_name' => $source->pageNo,
            'create_date_time' => $source->createDateTime,
            'bytes' => null,

            // True from the start and not a claim about this run: the file was gone before it began.
            'file_deleted' => true,
        ]);

        try {
            $deleted = $archive->hardDeleteSource($source->mvdId);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if (! $deleted) {
            $record->delete();

            return;
        }

        $record->update(['row_deleted' => true]);
        $this->rowsDeleted++;
    }

    /**
     * Whether the file store is answering at all.
     *
     * Everything this command does rests on "the file is not there" meaning what it says, and a store
     * that is unreachable says exactly the same thing about every file on it.
     */
    private function storeIsUp(FileStore $store, bool $quiet = false): bool
    {
        try {
            $store->list('');
        } catch (Throwable $exception) {
            if (! $quiet) {
                $this->components->error('The file store could not be read, so nothing was touched: '.$exception->getMessage());
                $this->line('  Every file would look deleted, and this command would take the archive apart.');
            }

            return false;
        }

        return true;
    }

    private function report(bool $confirmed, int $total): void
    {
        $this->newLine();

        $this->components->twoColumnDetail(
            'contents examined',
            number_format($this->checked).' of '.number_format($total).($this->checked < $total && ! $confirmed ? '  (--check raises this)' : ''),
        );

        // Without this the headline reads as "nothing is wrong" when the truth may be "almost none of
        // the contents looked at had a hidden source row to judge in the first place".
        $this->components->twoColumnDetail(
            'hidden source rows found in them',
            number_format($this->rowsDeleted + $this->filesStillThere + $this->unreadable),
        );
        $this->components->twoColumnDetail(
            $confirmed ? '<fg=yellow>source rows removed</>' : '<fg=yellow>source rows that would be removed</>',
            '<fg=yellow>'.number_format($this->rowsDeleted).'</>',
        );
        $this->components->twoColumnDetail('hidden sources whose file is still there', number_format($this->filesStillThere));

        if ($this->unreadable > 0) {
            $this->components->twoColumnDetail('sources the store would not answer for', number_format($this->unreadable));
        }

        if ($this->onShow > 0) {
            $this->components->twoColumnDetail('sources missing their file but NOT flagged deleted', number_format($this->onShow));
        }

        $this->newLine();

        if ($this->onShow > 0) {
            $this->line('  Those last ones are a different thing and this command does not touch them: the archive is');
            $this->line('  still offering those documents, so a missing file there is a fault to look into. It is also');
            $this->line('  where the skipped profiles sit - their files went but nothing ever flagged their rows,');
            $this->line('  because the pipeline never converted them.');
            $this->newLine();
        }

        if (! $confirmed && $this->rowsDeleted > 0) {
            $this->components->warn(sprintf('%s row(s) would be removed. Their files are already gone.', number_format($this->rowsDeleted)));
            $this->line('  Run it again with --confirm to do it.');
        } elseif ($confirmed) {
            $this->components->info(sprintf('%s orphaned source row(s) removed.', number_format($this->rowsDeleted)));
        } else {
            $this->components->info('Every hidden source row that was checked still has its file.');
        }

        if ($this->unreadable > 0) {
            $this->line('  The ones the store would not answer for were left alone; run it again and it will ask about them.');
        }
    }

    private function writesAreOn(): bool
    {
        return in_array(
            strtolower(trim((string) config('converter.archive.write_mode'))),
            ['on', 'true', '1', 'yes', 'enabled'],
            true,
        );
    }
}
