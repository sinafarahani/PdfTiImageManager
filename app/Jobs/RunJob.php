<?php

namespace App\Jobs;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\WorkerProcesses;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

class RunJob implements ShouldQueue
{
    use Queueable;

    /**
     * Never start a worker twice: a second instance would process the same folder.
     */
    public int $tries = 1;

    /**
     * The runner blocks while the worker runs, which can take hours.
     */
    public int $timeout = 0;

    /**
     * @param  int|null  $startVersion  Version of the panel state created by the Start that dispatched this job. When the
     *                                  state has changed since (a Stop, or another Start), the job does nothing.
     */
    public function __construct(
        protected string $dirPath,
        protected int $threads,
        protected string $runnerPath,
        protected ?int $startVersion = null,
    ) {}

    /**
     * Starts the worker in $dirPath (e{n}.exe) and then the runner, which blocks until it is done.
     */
    public function handle(ConverterStatus $converterStatus, WorkerProcesses $workerProcesses): void
    {
        $folderName = basename($this->dirPath);
        $exeFile = "e{$folderName}.exe";
        $statusFile = $this->dirPath.DIRECTORY_SEPARATOR.'status.txt';

        // After a quick Stop and Start the previous run of this worker may still be finishing its current file. Leave
        // "terminate" in status.txt so that the bridge ends it at a safe point, and start once it has exited. A previous
        // run that is still there long after the Stop is frozen and is ended by force.
        while ($workerProcesses->stopIsPending($this->dirPath) && $workerProcesses->isRunning($exeFile) && $this->isCurrentStart($converterStatus)) {
            $workerProcesses->endIfFrozen($this->dirPath);
            Sleep::for(2)->seconds();
        }

        if (! $this->isCurrentStart($converterStatus)) {
            Log::info("Worker {$folderName} not started: the converters were stopped or started again in the meantime.");

            return;
        }

        File::put($statusFile, '');
        File::put($this->dirPath.DIRECTORY_SEPARATOR.'log.txt', '');

        // share.txt: the worker's share folder, then its part of the available space in bytes (a whole number).
        $shareRoot = rtrim((string) config('app.shareRoot'), '\\/');
        $bytesPerWorker = intdiv((int) config('app.maxSize') * 1024 ** 3, max(1, $this->threads));
        File::put(
            $this->dirPath.DIRECTORY_SEPARATOR.'share.txt',
            "{$shareRoot}\\{$folderName}\\".PHP_EOL.$bytesPerWorker,
        );

        // 1. Start the long-running exe (non-blocking)
        $command = "cd /d \"{$this->dirPath}\" && Start \"\" \"{$exeFile}\"";
        pclose(popen($command, 'r'));

        // 2. Then run the runner (blocking, the queue waits until it has finished)
        $command2 = "\"{$this->runnerPath}\" \"{$exeFile}\"";

        exec($command2, $output2, $status2);

        if ($status2 !== 0) {
            Log::error("Runner command failed: {$command2}", [
                'status' => $status2,
                'output' => $output2,
            ]);
        } else {
            Log::info("Runner command finished: {$command2}", [
                'output' => $output2,
            ]);
        }
    }

    /**
     * Whether the Start that dispatched this job is still what the panel wants (jobs queued by older versions of the
     * panel carry no version and always start).
     */
    private function isCurrentStart(ConverterStatus $converterStatus): bool
    {
        if ($this->startVersion === null) {
            return true;
        }

        $state = $converterStatus->current();

        return $state['status'] === ConverterStatus::RUNNING && $state['version'] === $this->startVersion;
    }
}
