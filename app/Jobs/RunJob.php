<?php

namespace App\Jobs;

use App\Console\Commands\MonitorFreeze;
use App\Console\Commands\SaveAllIDs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schedule;
use Log;

class RunJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $threads;
    protected string $dirPath;

    protected string $runnerPath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $dirPath, int $threads, string $runnerPath)
    {
        $this->threads = $threads;
        $this->dirPath = $dirPath;
        $this->runnerPath = $runnerPath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $folderName = basename($this->dirPath);
        $exeFile = "e{$folderName}.exe";

        $statusFile = $this->dirPath . DIRECTORY_SEPARATOR . 'status.txt';
        File::put($statusFile, '');

        $logFile = $this->dirPath . DIRECTORY_SEPARATOR . 'log.txt';
        File::put($logFile, '');

        $sizeMangerFile = $this->dirPath . DIRECTORY_SEPARATOR . 'share.txt';

        $content = "f:\\{$folderName}\\" . PHP_EOL;
        $content .= (int) config('app.maxSize') * 1024 * 1024 * 1024 / $this->threads;

        File::put($sizeMangerFile, $content);

        // 1. Start the long-running exe (non-blocking)
        $command = "cd /d \"{$this->dirPath}\" && Start \"\" \"{$exeFile}\"";
        pclose(popen($command,"r"));

        // 2. Then run the runner (blocking, queue waits until finished)
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
        $flagKey = "enabled_$folderName";

        if (!Cache::has($flagKey)) {
            Cache::put($flagKey, true);
        }
    }
}
