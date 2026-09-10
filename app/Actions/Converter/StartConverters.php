<?php

namespace App\Actions\Converter;

use App\Jobs\RunJob;
use Illuminate\Support\Facades\File;
use RuntimeException;

class StartConverters
{
    public function __construct(private ConverterStatus $status) {}

    /**
     * Prepares the worker folders 0 .. $threads - 1 (copies of folder 0) and starts one worker in each. A worker whose
     * previous run is still finishing after a Stop starts once that run has exited (see RunJob).
     * Returns false when the converters are already running.
     *
     * @throws RuntimeException when the converter folder or its base folder "0" is missing
     */
    public function start(int $threads): bool
    {
        return $this->status->lock()->block(10, function () use ($threads): bool {
            if ($this->status->current()['status'] !== ConverterStatus::STOPPED) {
                return false;
            }

            $root = rtrim((string) config('app.pdfToImg'), '\\/');
            $template = $root.DIRECTORY_SEPARATOR.'0';

            if ($root === '' || ! File::isDirectory($template)) {
                throw new RuntimeException("The converter folder needs a base worker folder named 0: {$template}");
            }

            $folders = [];
            for ($index = 0; $index < $threads; $index++) {
                $folder = $root.DIRECTORY_SEPARATOR.$index;

                if (! File::isDirectory($folder)) {
                    $this->createWorkerFolder($template, $folder, $index);
                }

                $folders[] = $folder;
            }

            $version = $this->status->set(ConverterStatus::RUNNING, $threads);

            foreach ($folders as $folder) {
                RunJob::dispatch($folder, $threads, (string) config('app.runnerPath'), $version);
            }

            return true;
        });
    }

    /**
     * Copies the base worker folder and renames its files for worker $index (e0.exe becomes e{index}.exe, ...).
     */
    private function createWorkerFolder(string $template, string $folder, int $index): void
    {
        if (! File::copyDirectory($template, $folder)) {
            throw new RuntimeException("Cannot create the worker folder {$folder}");
        }

        $renames = [
            'e0.exe' => "e{$index}.exe",
            '0.exe' => "{$index}.exe",
            'e0.exe.config' => "e{$index}.exe.config",
        ];

        foreach ($renames as $from => $to) {
            $source = $folder.DIRECTORY_SEPARATOR.$from;

            if (File::exists($source)) {
                File::move($source, $folder.DIRECTORY_SEPARATOR.$to);
            }
        }

        $configTxt = $folder.DIRECTORY_SEPARATOR.'config.txt';
        if (File::exists($configTxt)) {
            File::put($configTxt, (string) $index);
        }

        // The shared folder in the .NET configuration points at the base worker's folder (for example d:\0\).
        $configXml = $folder.DIRECTORY_SEPARATOR."e{$index}.exe.config";
        if (File::exists($configXml)) {
            File::put($configXml, (string) preg_replace(
                '/([A-Za-z]:(?:\\\\){1,2})0((?:\\\\){1,2})/',
                '${1}'.$index.'${2}',
                File::get($configXml),
            ));
        }
    }
}
