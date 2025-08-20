<?php

namespace App\Jobs;

use App\Models\LastStop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\File;

class TerminateJob implements ShouldQueue
{
    use Queueable;

    protected string $baseDirPath;

    /**
     * Create a new job instance.
     */
    public function __construct(string $baseDirPath)
    {
        $this->baseDirPath = $baseDirPath;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $output = [];
        exec('wmic process where "name=\'e' . $this->baseDirPath . '.exe\'" get CreationDate,ProcessId /format:csv', $output);

        while (count($output) > 2) {
            $output = [];
            exec('wmic process where "name=\'e' . $this->baseDirPath . '.exe\'" get CreationDate,ProcessId /format:csv', $output);
            sleep(1);
        }

        if (File::exists("F:\\$this->baseDirPath")) {
            $shareFolderPath = File::directories("F:\\$this->baseDirPath");

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
            LastStop::firstOrCreate([
                'MVD ID' => $pdfFileName,
            ]);
        }
    }
}
