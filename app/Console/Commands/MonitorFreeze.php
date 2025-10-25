<?php

namespace App\Console\Commands;

use App\Jobs\RunJob;
use App\Models\Freeze;
use DateTime;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

class MonitorFreeze extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor-freeze {folder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a process is frozen (>30min) and handle termination + restart';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $folderName = $this->argument('folder');
        $processName = $folderName.'.exe';
        // Query process creation time via WMIC
        $output = [];
        Log::info('checking: ' . $processName);
        exec('wmic process where "name=\'' . $processName . '\'" get CreationDate,ProcessId /format:csv', $output);

        if (count($output) <= 2) {
            $this->info("{$processName} is not running.");
            return;
        }

        $str = explode(',', end($output));

        preg_match('/(\d{14})\.(\d+)([+-]\d+)/', $str[1], $matches);

        $dt = DateTime::createFromFormat('YmdHis', $matches[1]);

        $dt->modify(-(int)$matches[3] . ' minutes');

        Log::info('start time: ' . $dt->format('Y-m-d H:i:s'));
        Log::info('current time: ' . (new DateTime())->format('Y-m-d H:i:s'));
        $runtimeMinutes = (time() - $dt->getTimestamp()) / 60;

        Log::info('process ' . $processName . ' is running for minutes: ' . $runtimeMinutes);

        if ($runtimeMinutes >= 60) {
            $dependentProcess = 'e' . $processName;
            Log::info('terminating: ' . $processName);
            exec('taskkill /F /IM ' . $dependentProcess);
            exec('taskkill /F /IM ' . $processName);

            if (File::exists("F:\\$folderName")) {
                $shareFolderPath = File::directories("F:\\$folderName");

                $lastModifiedDir = null;
                $lastModifiedTime = 0;

                foreach ($shareFolderPath as $p) {
                    $modified = File::lastModified($p);
                    if ($modified > $lastModifiedTime) {
                        $lastModifiedTime = $modified;
                        $lastModifiedDir = $p;
                    }
                }

                $pdfFiles = File::files($lastModifiedDir);

                $pdfFileName = null;
                foreach ($pdfFiles as $file) {
                    if (strtolower($file->getExtension()) === 'pdf') {
                        $pdfFileName = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                        break;
                    }
                }

                Freeze::firstOrCreate([
                    'MVD ID' => $pdfFileName,
                ]);
            }

            $runnerPath = config('app.runnerPath');
            $dirPath = config('app.pdfToImg') . DIRECTORY_SEPARATOR . $folderName;
            $threads = Cache::get('action_threads', 1);

            Log::info('restarting: ' . $dirPath . '\\' . $threads);
            RunJob::dispatch($dirPath, $threads ,$runnerPath);

            $this->info("{$processName} froze (>30min). Killed {$processName} & {$dependentProcess}. Run job dispatched.");
        } else {
            $this->info("{$processName} running fine ({$runtimeMinutes} minutes).");
        }
    }
}
