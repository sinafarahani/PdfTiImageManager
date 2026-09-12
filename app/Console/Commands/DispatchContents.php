<?php

namespace App\Console\Commands;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Jobs\ConvertContent;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Throwable;

class DispatchContents extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:dispatch {--once : hand out one batch and stop}';

    /**
     * @var string
     */
    protected $description = 'Hand contents from the queue to the converters, for as long as the panel says Start';

    /**
     * Who is handing out work, in the cache every process shares. Two dispatchers are not a disaster
     * - a content can only be claimed once - but they would hold twice as much claimed work as the
     * workers can convert, and the surplus sits claimed until it goes stale and costs an attempt. A
     * supervisor that was killed leaves its dispatcher running, so the next supervisor's dispatcher
     * finds the role taken and stands by instead of exiting: when the orphan goes, it takes over
     * within ROLE_SECONDS without anybody restarting anything.
     */
    private const string ROLE_KEY = 'converter.dispatcher';

    /**
     * The shortest time the role is held for after the last renewal. It is refreshed at the top of
     * every pass and a pass sleeps at most converter.queue.max_idle_seconds, so the real figure is
     * three times that idle cap - long enough that a busy pass cannot lose the role, short enough
     * that a dispatcher which was killed rather than stopped does not keep the next one standing by
     * for long. A dispatcher that ends its loop gives the role back at once.
     */
    private const int ROLE_SECONDS = 120;

    /**
     * The failures that mean the machine is broken rather than the document, and how many of them in
     * how few minutes say so. A damaged PDF fails at "render" and a content with no PDF row at
     * "metadata"; everything else in this list is the disk, the FTP site or the archive, and when
     * that is what is failing, handing out more work marks perfectly good documents failed three
     * attempts at a time - 500,000 of them are waiting. The threshold is counted per slot so that it
     * scales with the pool, and tripping it only ever costs a pause.
     *
     * @var list<string>
     */
    private const array MACHINE_STAGES = [
        Stage::Reserve->value,
        Stage::Download->value,
        Stage::Thumbnail->value,
        Stage::Write->value,
        Stage::Upload->value,
        Stage::Finish->value,
    ];

    private const int BREAKER_WINDOW_MINUTES = 5;

    private const int BREAKER_FAILURES_PER_SLOT = 3;

    /**
     * The loop that replaced the old "for (i = 0; i < length; i += loopIteration)" batch: it asks the
     * queue for work and waits when there is none, instead of counting the work up front and exiting
     * when the count runs out. Nothing here decides what to convert - the queue does.
     */
    public function handle(ConverterStatus $status, ConversionQueue $queue): int
    {
        $worker = $this->workerName();
        $idle = max(1, (int) config('converter.queue.idle_seconds'));
        $maxIdle = max($idle, (int) config('converter.queue.max_idle_seconds'));

        $this->info("Dispatcher {$worker} started.");

        try {
            return $this->handOutUntilStopped($status, $queue, $worker, $idle, $maxIdle);
        } finally {
            $this->giveTheRoleBack($worker);
        }
    }

    /**
     * @return int<0, 255>
     */
    private function handOutUntilStopped(
        ConverterStatus $status,
        ConversionQueue $queue,
        string $worker,
        int $idle,
        int $maxIdle,
    ): int {
        $wait = $idle;

        do {
            if (! $this->holdTheRole($worker, $maxIdle)) {
                $this->line('Another dispatcher is handing out work; standing by.');

                if ($this->option('once')) {
                    return self::SUCCESS;
                }

                Sleep::for($idle)->seconds();

                continue;
            }

            $state = $status->current();

            if ($state['status'] !== ConverterStatus::RUNNING) {
                // Stopped means "hand out nothing new". Conversions already running finish normally;
                // that is the whole of the safe stop, and it needs no files and no signals.
                if ($this->option('once')) {
                    return self::SUCCESS;
                }

                Sleep::for($idle)->seconds();

                continue;
            }

            try {
                $handedOut = $this->handOut($queue, $worker, (int) $state['threads']);
            } catch (Throwable $exception) {
                // A database hiccup must not end the dispatcher: it is the one process that has to
                // survive, or nothing is converted until somebody notices.
                report($exception);
                $this->error('Could not hand out work: '.$exception->getMessage());
                Sleep::for($maxIdle)->seconds();

                continue;
            }

            if ($handedOut > 0) {
                $this->line("Handed out {$handedOut} content(s).");
                $wait = $idle;

                continue;
            }

            // Nothing waiting, or every slot busy: wait longer each time, up to the cap, so an empty
            // queue does not hammer the database.
            Sleep::for($wait)->seconds();
            $wait = min($wait * 2, $maxIdle);
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /**
     * Claims as many contents as there is room for and queues one job each. The room is what keeps a
     * Stop from leaving a long tail of claimed work: at most two per worker are ever outstanding.
     */
    private function handOut(ConversionQueue $queue, string $worker, int $workers): int
    {
        $workers = $workers > 0 ? $workers : (int) config('converter.queue.workers');
        $capacity = max(1, $workers) * max(1, (int) config('converter.queue.queued_per_worker'));

        if ($this->machineLooksBroken($capacity)) {
            return 0;
        }

        $room = $capacity - $this->outstanding();

        if ($room <= 0) {
            return 0;
        }

        $claimed = $queue->claim($worker, $room);

        foreach ($claimed as $conversion) {
            // The claim's own timestamp travels with the job, so a job that is run long after the
            // reconciler took its content back cannot act on whoever holds it now.
            ConvertContent::dispatch($conversion->id, $conversion->claimed_at?->toDateTimeString())
                ->onConnection((string) config('converter.queue.connection'))
                ->onQueue((string) config('converter.queue.name'));
        }

        return $claimed->count();
    }

    /**
     * The work already handed out and not finished, counted from the conversions table rather than
     * from the queue's own depth.
     *
     * The queue's depth is the wrong number for two reasons. config/queue.php sets the database
     * connection's retry_after to ten years on purpose, so a job whose worker was killed stays
     * reserved and keeps being counted for ever - eight of those and the dispatcher would hand out
     * nothing again, silently, until somebody emptied the jobs table. And a job that ran is gone from
     * the queue while its conversion may still be in flight. A claimed row is exactly "handed out and
     * not finished", and it is the reconciler's job to clear the ones nobody is working on.
     */
    private function outstanding(): int
    {
        return Conversion::query()->claimed()->count();
    }

    /**
     * Whether the recent failures say the machine is broken rather than the documents. True pauses
     * the hand-out for one pass, which is all a circuit breaker here has to do: the work stays
     * waiting in the queue, and the dispatcher tries again on the next pass.
     */
    private function machineLooksBroken(int $capacity): bool
    {
        $threshold = $capacity * self::BREAKER_FAILURES_PER_SLOT;

        $failures = Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->where('finished_at', '>=', now()->subMinutes(self::BREAKER_WINDOW_MINUTES))
            ->whereIn('failure_stage', self::MACHINE_STAGES)
            ->count();

        if ($failures < $threshold) {
            return false;
        }

        $this->error(sprintf(
            '%d content(s) failed on the disk, the FTP site or the archive in the last %d minutes; handing out nothing until that clears.',
            $failures,
            self::BREAKER_WINDOW_MINUTES,
        ));

        /** @var Conversion|null $last */
        $last = Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->whereIn('failure_stage', self::MACHINE_STAGES)
            ->orderByDesc('finished_at')
            ->first(['content_id', 'failure_stage', 'failure_reason']);

        if ($last !== null) {
            $this->line(sprintf(
                '  the last one was %s while %s: %s',
                $last->content_id,
                $last->failure_stage?->label() ?? 'working',
                (string) $last->failure_reason,
            ));
        }

        return true;
    }

    /**
     * Takes or renews the dispatcher role. Cache::add is the atomic put-if-absent every cache store
     * has, so two dispatchers starting in the same second cannot both get it; the renewal is a plain
     * put, which only the holder ever reaches.
     */
    private function holdTheRole(string $worker, int $maxIdle): bool
    {
        $seconds = max(self::ROLE_SECONDS, $maxIdle * 3);

        if (Cache::add(self::ROLE_KEY, $worker, $seconds)) {
            return true;
        }

        if (Cache::get(self::ROLE_KEY) !== $worker) {
            return false;
        }

        Cache::put(self::ROLE_KEY, $worker, $seconds);

        return true;
    }

    /**
     * Handed back on the way out, so the dispatcher the supervisor starts next is handing out work
     * again immediately instead of standing by until the role expires.
     */
    private function giveTheRoleBack(string $worker): void
    {
        if (Cache::get(self::ROLE_KEY) === $worker) {
            Cache::forget(self::ROLE_KEY);
        }
    }

    private function workerName(): string
    {
        return substr(sprintf('dispatcher@%s:%d', gethostname() ?: 'unknown', getmypid() ?: 0), 0, 100);
    }
}
