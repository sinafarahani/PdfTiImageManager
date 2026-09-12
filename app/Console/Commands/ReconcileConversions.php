<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use App\Models\ConversionPage;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Throwable;

/**
 * Puts back the conversions a stopped worker left half finished.
 *
 * This replaces the rebuild the user does by hand today. When a worker was killed, froze or lost the
 * machine in the middle of a content, the archive was left with a content reserved to a worker that
 * no longer exists and with however many page rows and uploaded images that attempt had got through -
 * and the only way back was to find them by eye and delete them.
 *
 * Because conversion_pages records every MVDContent row and every upload as it happens, the cleanup
 * is exact rather than a guess. For each conversion whose heartbeat stopped, this command guarantees:
 *
 *  - the conversion is taken over first, so two reconcilers - or a reconciler and the worker that was
 *    only slow - cannot both clean up one content,
 *  - every page row that attempt wrote is deleted from the archive (ImageLayer and ThumbLayer follow
 *    through their cascading foreign keys, so nothing of it is left behind),
 *  - GeneralContent.Reserved is set free again, so the content can be taken by any worker,
 *  - the attempt is counted, and the content goes back to pending - or stays failed once
 *    converter.failure.max_attempts is used up, so a content that kills its worker every time cannot
 *    loop forever,
 *  - the local page ledger for that attempt is emptied, so the next attempt starts from nothing.
 *
 * What it deliberately does not do is touch FTP: deleting files is the pipeline's job, not a console
 * command's. reclaim() returns the remote paths of the images that attempt had already uploaded, and
 * the caller removes them. An image left behind harms nothing in the archive - no row points at it
 * any more - but it fills the disk, which is why the paths are handed over rather than dropped.
 */
class ReconcileConversions extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:reconcile
        {--limit=200 : Stale conversions to reclaim in this run}
        {--minutes= : Treat a conversion as stale after this many minutes instead of the configured window}';

    /**
     * @var string
     */
    protected $description = 'Clean up conversions whose worker stopped, and queue them again';

    public function handle(ArchiveGateway $archive, ConversionQueue $queue): int
    {
        $limit = (int) $this->option('limit');

        if ($limit < 1) {
            $this->error('The limit must be at least 1.');

            return self::FAILURE;
        }

        $reclaimed = 0;
        $orphans = [];
        $stopped = false;

        // The window is normally the configured one, which has to be longer than the longest step a
        // healthy conversion runs without reporting. The supervisor overrides it at startup, where
        // nothing of ours can be running yet and a machine that has just booted would otherwise leave
        // whatever it was converting sitting claimed for an hour and a half.
        $minutes = $this->option('minutes');
        $before = $minutes === null ? null : now()->subMinutes(max(0, (int) $minutes));

        foreach ($queue->stale($limit, $before) as $conversion) {
            $this->warn(sprintf(
                'Reclaiming %s from worker %s (last heartbeat %s).',
                $conversion->content_id,
                $conversion->worker ?? 'unknown',
                $conversion->heartbeat_at?->diffForHumans() ?? 'never',
            ));

            try {
                $paths = $this->reclaim($conversion, $archive, $queue);
            } catch (Throwable $exception) {
                // The cleanup deletes page rows and releases the content, so it needs a reachable
                // archive and CONVERTER_WRITE_MODE=on - and this command runs from the scheduler
                // every minute, which would otherwise mean a stack trace a minute for as long as the
                // archive is down or the panel is in dry-run mode.
                //
                // It stops rather than going on to the next conversion: every one of them would fail
                // the same way, and each would first be taken over (its worker column rewritten to
                // this reconciler) and then left standing, which is the state the next run has to
                // wait a full staleness window to see again.
                report($exception);

                $this->error('  could not be cleaned up: '.$this->causeOf($exception));
                $this->line('  it stays claimed, and the next run tries again; the other stale conversions were left untouched.');
                $this->line('  the cleanup writes to the archive: it needs a reachable archive and CONVERTER_WRITE_MODE=on in .env.');

                $stopped = true;

                break;
            }

            if ($paths === null) {
                $this->line('  another reconciler has it; left alone.');

                continue;
            }

            $reclaimed++;

            foreach ($paths as $path) {
                $orphans[] = $path;
            }
        }

        $deleted = $this->deleteOrphans($orphans);

        $this->info(sprintf(
            'Reclaimed %d conversion(s); deleted %d of %d image(s) the interrupted attempts had uploaded.',
            $reclaimed,
            $deleted,
            count($orphans),
        ));

        return $stopped ? self::FAILURE : self::SUCCESS;
    }

    /**
     * What stopped the cleanup, without the statement it happened on. A QueryException repeats the
     * whole SQL and its bindings in its message, which is right for the log report() just made and
     * wrong for the single line a scheduled command leaves behind.
     */
    private function causeOf(Throwable $exception): string
    {
        return $exception instanceof QueryException
            ? ($exception->getPrevious()?->getMessage() ?: $exception->getMessage())
            : $exception->getMessage();
    }

    /**
     * Undoes one interrupted conversion and returns the remote paths of the images it had already
     * uploaded, for the caller to delete from FTP. Null means the conversion was not ours to undo and
     * nothing was touched.
     *
     * The claim is taken over before anything is read: a second reconciler - or a worker whose
     * heartbeat only looked dead - may already have put the content back in the queue and a new
     * attempt may already be writing pages for it, and deleting those would be exactly the mistake
     * this ledger exists to prevent.
     *
     * The archive is then cleaned before anything local is: if deletePages() fails, the ledger still
     * names the rows and the next run tries again, whereas the other order would lose them for good.
     * For the same reason the content is released only once its rows are gone, so that no other worker
     * can pick it up while half of the previous attempt is still standing.
     *
     * @return list<string>|null
     */
    /**
     * Removes the images an interrupted attempt had already uploaded, and returns how many went.
     *
     * Its page rows have just been deleted, so nothing in the archive points at these files any more
     * and the next attempt uploads its own under new ids: left alone they are dead weight on the share
     * drive that nothing would ever find again. A store that cannot be reached is reported and not
     * retried here - the paths are in conversion_pages until the row is replaced, and the sweep is not
     * worth failing a reclaim over.
     *
     * @param  list<string>  $paths
     */
    private function deleteOrphans(array $paths): int
    {
        if ($paths === []) {
            return 0;
        }

        try {
            $files = app(FileStore::class);
        } catch (Throwable $exception) {
            report($exception);
            $this->warn(sprintf('  %d uploaded image(s) could not be deleted: the file store is not reachable.', count($paths)));

            return 0;
        }

        $deleted = 0;

        foreach ($paths as $path) {
            try {
                $files->delete($path);
                $deleted++;
            } catch (FileStoreException $exception) {
                $this->line("  image left on the file store: {$path} ({$exception->getMessage()})");
            }
        }

        return $deleted;
    }

    public function reclaim(Conversion $conversion, ArchiveGateway $archive, ConversionQueue $queue): ?array
    {
        $stoppedWorker = $conversion->worker ?? 'unknown';

        if (! $queue->takeOver($conversion, $this->reclaimer())) {
            return null;
        }

        /** @var Collection<int, ConversionPage> $pages */
        $pages = $conversion->pages()->orderBy('seq')->get();

        /** @var list<string> $mvdIds */
        $mvdIds = $pages->pluck('mvd_id')->filter()->values()->all();

        /** @var list<string> $uploaded */
        $uploaded = $pages->filter(fn (ConversionPage $page): bool => $page->uploaded)
            ->pluck('remote_path')
            ->filter()
            ->values()
            ->all();

        if ($mvdIds !== []) {
            $archive->deletePages($mvdIds);
        }

        $conversion->pages()->delete();

        $archive->release($conversion->content_id);

        // Counted as an attempt through the queue's own failure rule, so an interrupted content obeys
        // the same max_attempts as one that failed outright.
        $queue->fail(
            $conversion,
            Stage::Cleanup,
            sprintf(
                'Worker %s stopped sending a heartbeat; %d page row(s) were removed and the content was queued again.',
                $stoppedWorker,
                count($mvdIds),
            ),
            retryable: true,
        );

        return $uploaded;
    }

    /**
     * Who is doing the cleaning. It goes into the conversion's worker column while the reclaim runs,
     * which is how a second reconciler is kept out; the worker that abandoned the content is named in
     * the failure reason instead, where it is of use to a person.
     */
    private function reclaimer(): string
    {
        return substr(sprintf('reconcile:%s:%d', gethostname() ?: 'unknown', getmypid() ?: 0), 0, 100);
    }
}
