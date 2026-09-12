<?php

namespace App\Console\Commands;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Jobs\ConvertContent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
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
     * The loop that replaced the old "for (i = 0; i < length; i += loopIteration)" batch: it asks the
     * queue for work and waits when there is none, instead of counting the work up front and exiting
     * when the count runs out. Nothing here decides what to convert - the queue does.
     */
    public function handle(ConverterStatus $status, ConversionQueue $queue): int
    {
        $worker = $this->workerName();
        $idle = max(1, (int) config('converter.queue.idle_seconds'));
        $maxIdle = max($idle, (int) config('converter.queue.max_idle_seconds'));
        $wait = $idle;

        $this->info("Dispatcher {$worker} started.");

        do {
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
        $room = $capacity - $this->queued();

        if ($room <= 0) {
            return 0;
        }

        $claimed = $queue->claim($worker, $room);

        foreach ($claimed as $conversion) {
            ConvertContent::dispatch($conversion->id)
                ->onConnection((string) config('converter.queue.connection'))
                ->onQueue((string) config('converter.queue.name'));
        }

        return $claimed->count();
    }

    private function queued(): int
    {
        return Queue::connection((string) config('converter.queue.connection'))
            ->size((string) config('converter.queue.name'));
    }

    private function workerName(): string
    {
        return 'dispatcher@'.(gethostname() ?: 'unknown');
    }
}
