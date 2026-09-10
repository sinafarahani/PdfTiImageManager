<?php

namespace App\Actions\Converter;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * The Windows processes of the workers. Worker n runs e{n}.exe in its folder; the bridge and the converter run as its
 * children. A worker is asked to finish with "terminate" in its status.txt and normally exits within a minute.
 */
class WorkerProcesses
{
    private const string OVERVIEW_KEY = 'converter.processes';

    /**
     * Numbers of the workers whose e{n}.exe is running: started ones, and stopping ones (asked to finish). Kept for two
     * seconds, so that the dashboards open in several browsers share one tasklist call.
     *
     * @return array{started: list<int>, stopping: list<int>}
     */
    public function overview(): array
    {
        return Cache::remember(self::OVERVIEW_KEY, 2, function (): array {
            $root = rtrim((string) config('app.pdfToImg'), '\\/');
            $overview = ['started' => [], 'stopping' => []];

            foreach ($this->runningWorkerNumbers() as $number) {
                $overview[$this->stopIsPending($root.DIRECTORY_SEPARATOR.$number) ? 'stopping' : 'started'][] = $number;
            }

            return $overview;
        });
    }

    /**
     * Makes the next overview() look at the processes again, after the status files have changed.
     */
    public function forgetOverview(): void
    {
        Cache::forget(self::OVERVIEW_KEY);
    }

    /**
     * Whether worker $folder has been asked to finish (status.txt = "terminate").
     */
    public function stopIsPending(string $folder): bool
    {
        $statusFile = $this->statusFile($folder);

        return File::exists($statusFile) && trim(File::get($statusFile)) === 'terminate';
    }

    public function isRunning(string $imageName): bool
    {
        return $this->processIds($imageName) !== [];
    }

    /**
     * Ends worker $folder by force when it was asked to finish at least config('app.forceStopAfterMinutes') minutes ago
     * and is still running: it is frozen (a worker that is still working finishes within a minute). Kills the converter
     * {n}.exe, then e{n}.exe, each with its child processes (the names are unique to the folder). Returns true when a
     * worker was ended.
     */
    public function endIfFrozen(string $folder): bool
    {
        if (! $this->stopIsPending($folder)) {
            return false;
        }

        $statusFile = $this->statusFile($folder);
        clearstatcache(true, $statusFile);
        $minutes = (int) config('app.forceStopAfterMinutes');

        if (now()->getTimestamp() - File::lastModified($statusFile) < $minutes * 60) {
            return false;
        }

        $workerProcessIds = $this->processIds('e'.basename($folder).'.exe');

        if ($workerProcessIds === []) {
            return false;
        }

        Log::warning("Worker {$folder} did not finish within {$minutes} minutes after Stop; it is frozen and is ended by force.");

        // The converter first: while e{n}.exe is still there, RunJob does not start the worker again.
        foreach ([...$this->processIds(basename($folder).'.exe'), ...$workerProcessIds] as $processId) {
            Process::run(['taskkill', '/F', '/T', '/PID', (string) $processId]);
        }

        return true;
    }

    /**
     * @return list<int>
     */
    private function runningWorkerNumbers(): array
    {
        $result = Process::run(['tasklist', '/FO', 'CSV', '/NH']);
        preg_match_all('/^"e(\d+)\.exe"/im', $result->output(), $matches);

        return array_values(array_unique(array_map(intval(...), $matches[1])));
    }

    /**
     * @return list<int>
     */
    private function processIds(string $imageName): array
    {
        $result = Process::run(['tasklist', '/FI', "IMAGENAME eq {$imageName}", '/NH', '/FO', 'CSV']);
        $processIds = [];

        foreach (preg_split('/\r?\n/', trim($result->output())) as $line) {
            $fields = str_getcsv($line, ',', '"', '');

            if (count($fields) >= 2 && strcasecmp((string) $fields[0], $imageName) === 0 && ctype_digit((string) $fields[1])) {
                $processIds[] = (int) $fields[1];
            }
        }

        return $processIds;
    }

    private function statusFile(string $folder): string
    {
        return $folder.DIRECTORY_SEPARATOR.'status.txt';
    }
}
