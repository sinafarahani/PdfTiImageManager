<?php

namespace App\Console\Commands;

use App\Actions\Converter\ConverterStatus;
use Illuminate\Console\Command;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;

/**
 * The one process Windows has to start: it keeps the dispatcher and the converter workers alive.
 *
 * A worker is a queue worker that takes a single content and exits (--max-jobs=1). That is what makes
 * Start and Stop instant and safe on Windows, where a queue worker cannot be signalled: stopping
 * simply means no new worker is started, so the conversion in flight finishes and nothing is killed.
 * It also means no worker lives long enough to leak the memory of an image library.
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

    public function handle(ConverterStatus $status): int
    {
        $passes = (int) $this->option('passes');
        $pass = 0;

        /** @var InvokedProcess|null $dispatcher */
        $dispatcher = null;

        /** @var list<InvokedProcess> $workers */
        $workers = [];

        $this->info('Supervisor started. Start and Stop are controlled from the panel.');

        while ($passes === 0 || $pass < $passes) {
            $pass++;

            if ($dispatcher === null || ! $dispatcher->running()) {
                $dispatcher = $this->start(['converters:dispatch']);
                $this->line('Dispatcher started.');
            }

            $workers = array_values(array_filter($workers, fn (InvokedProcess $worker): bool => $worker->running()));

            $state = $status->current();
            $wanted = $state['status'] === ConverterStatus::RUNNING
                ? max(1, (int) ($state['threads'] ?: config('converter.queue.workers')))
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
