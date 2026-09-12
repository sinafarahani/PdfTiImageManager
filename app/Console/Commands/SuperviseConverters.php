<?php

namespace App\Console\Commands;

use App\Actions\Converter\ConverterStatus;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Illuminate\Contracts\Process\InvokedProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The one process Windows has to start: it keeps the dispatcher and the converter workers alive.
 *
 * A worker is a queue worker that takes a single content and exits (--max-jobs=1). That is what makes
 * Start and Stop instant and safe on Windows, where a queue worker cannot be signalled: stopping
 * simply means no new worker is started, so the conversion in flight finishes and nothing is killed.
 * It also means no worker lives long enough to leak the memory of an image library.
 *
 * Only one supervisor may run at a time, and that is enforced rather than assumed. Nothing stops a
 * person from starting it twice - a Task Scheduler entry plus a console window, or a second one after
 * the first was killed - and a second supervisor would quietly run a second pool: eight workers while
 * the panel says four, all of them claiming work. The role is taken in the cache every process of the
 * panel shares, renewed on every pass, and released by expiry when the supervisor is killed, so the
 * next one takes over on its own.
 */
class SuperviseConverters extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:supervise {--passes=0 : stop after this many passes (0 runs forever)}';

    /**
     * @var string
     */
    protected $description = 'Keep the dispatcher and the converter workers running';

    private const string ROLE_KEY = 'converter.supervisor';

    /**
     * How long the role is held after the last renewal. A pass takes about a second, so this is
     * generous; it is also how long after a kill the next supervisor has to wait.
     */
    private const int ROLE_SECONDS = 60;

    /**
     * The shortest time between two starts of the dispatcher. Without it, a dispatcher that exits
     * immediately - a bad .env, a database that is not there yet - would be started once a second for
     * as long as the supervisor runs.
     */
    private const int RESTART_BACKOFF_SECONDS = 5;

    public function handle(ConverterStatus $status): int
    {
        $me = $this->supervisorName();

        if (! $this->holdTheRole($me)) {
            $this->error(sprintf(
                'Another supervisor (%s) is already running this panel; this one is not starting anything.',
                (string) Cache::get(self::ROLE_KEY),
            ));

            return self::FAILURE;
        }

        $passes = (int) $this->option('passes');
        $pass = 0;

        /** @var InvokedProcess|null $dispatcher */
        $dispatcher = null;
        $dispatcherStartedAt = null;

        /** @var list<InvokedProcess> $workers */
        $workers = [];

        $this->info('Supervisor started. Start and Stop are controlled from the panel.');

        try {
            while ($passes === 0 || $pass < $passes) {
                $pass++;

                // Renewed before anything is started, so a supervisor that has lost the role - its
                // cache was cleared, or it was suspended long enough to expire - stops running a
                // second pool behind the one that took over.
                if (! $this->holdTheRole($me)) {
                    $this->error('The supervisor role was taken over; stopping without touching the conversions in flight.');

                    return self::FAILURE;
                }

                if ($dispatcher !== null && ! $dispatcher->running()) {
                    $this->reportExit('Dispatcher', $dispatcher);
                    $dispatcher = null;
                }

                if ($dispatcher === null && $this->mayStartDispatcher($dispatcherStartedAt)) {
                    $dispatcher = $this->start(['converters:dispatch']);
                    $dispatcherStartedAt = microtime(true);
                    $this->line('Dispatcher started.');
                }

                $workers = array_values(array_filter(
                    $workers,
                    function (InvokedProcess $worker): bool {
                        if ($worker->running()) {
                            return true;
                        }

                        $this->reportExit('Worker', $worker);

                        return false;
                    },
                ));

                $state = $status->current();
                $slots = max(1, (int) ($state['threads'] ?: config('converter.queue.workers')));

                // After a Stop the dispatcher takes nothing new, but the contents it had already
                // handed out are still claimed, with their jobs waiting in the queue. Workers keep
                // being started until those are finished: without this they would sit claimed until
                // they went stale an hour later, each costing its content an attempt for work nobody
                // was doing. A claim whose job was lost keeps one worker respawning idly until the
                // reconciler clears it, which is a short php process a minute and self-healing.
                $wanted = $state['status'] === ConverterStatus::RUNNING || Conversion::query()->claimed()->exists()
                    ? $slots
                    : 0;

                for ($slot = count($workers); $slot < $wanted; $slot++) {
                    $workers[] = $this->start([
                        'queue:work',
                        (string) config('converter.queue.connection'),
                        '--queue='.config('converter.queue.name'),
                        '--max-jobs=1',
                        '--max-time=60',
                        '--sleep=3',
                        '--tries=1',
                    ]);
                }

                Sleep::for(1)->second();
            }

            return self::SUCCESS;
        } finally {
            $this->handOver($me, $dispatcher, count($workers));
        }
    }

    /**
     * Stops handing out work and gives the role up, so the next supervisor can start at once.
     *
     * The dispatcher is stopped because a dispatcher without a supervisor keeps claiming contents
     * that no worker will convert; they would sit claimed until they went stale, each costing the
     * content an attempt. The workers are deliberately left alone: a worker holds a content, and
     * killing it in the middle of a conversion is the one thing this pipeline goes to such lengths to
     * clean up after. They take one content each and exit by themselves.
     *
     * None of this runs when the supervisor is killed rather than asked to stop - Windows does not
     * give a console application the chance - which is why the role expires on its own and the
     * dispatcher keeps its own role in the cache.
     *
     * stop() is on both InvokedProcess implementations (the real one and the fake) but not on the
     * contract they share, which is the type this command holds so that Process::fake() can be used
     * against it.
     */
    private function handOver(string $me, ?InvokedProcess $dispatcher, int $workers): void
    {
        if ($dispatcher !== null && $dispatcher->running()) {
            try {
                $dispatcher->stop();
                $this->line('Dispatcher stopped.');
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($workers > 0) {
            $this->line(sprintf('%d worker(s) are still finishing a content; they were left alone.', $workers));
        }

        if (Cache::get(self::ROLE_KEY) === $me) {
            Cache::forget(self::ROLE_KEY);
        }
    }

    /**
     * Says what a child process's exit was, because a dispatcher that dies silently means nothing is
     * converted at all and the panel would go on showing Started.
     */
    private function reportExit(string $what, InvokedProcess $process): void
    {
        try {
            $result = $process->wait();
        } catch (Throwable $exception) {
            report($exception);

            return;
        }

        if ($result->successful()) {
            return;
        }

        $message = sprintf(
            '%s exited with code %d: %s',
            $what,
            $result->exitCode() ?? -1,
            trim($result->errorOutput()) !== '' ? trim($result->errorOutput()) : trim($result->output()),
        );

        $this->error($message);
        Log::error($message);
    }

    private function mayStartDispatcher(?float $lastStartedAt): bool
    {
        return $lastStartedAt === null
            || (microtime(true) - $lastStartedAt) >= self::RESTART_BACKOFF_SECONDS;
    }

    /**
     * Takes or renews the supervisor role. Cache::add is the atomic put-if-absent every cache store
     * has, so two supervisors starting at the same moment cannot both get it.
     */
    private function holdTheRole(string $me): bool
    {
        if (Cache::add(self::ROLE_KEY, $me, self::ROLE_SECONDS)) {
            return true;
        }

        if (Cache::get(self::ROLE_KEY) !== $me) {
            return false;
        }

        Cache::put(self::ROLE_KEY, $me, self::ROLE_SECONDS);

        return true;
    }

    private function supervisorName(): string
    {
        return sprintf('supervisor@%s:%d', gethostname() ?: 'unknown', getmypid() ?: 0);
    }

    /**
     * @param  list<string>  $arguments
     */
    private function start(array $arguments): InvokedProcess
    {
        return Process::path(base_path())
            ->timeout(0)
            ->start(array_merge([PHP_BINARY, 'artisan'], $arguments, ['--no-interaction']));
    }
}
