<?php

namespace App\Actions\Converter;

use App\Jobs\RunJob;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

class StartConverters
{
    public function __construct(
        private ConverterStatus $status,
        private WorkerProcesses $workerProcesses,
    ) {}

    /**
     * Prepares the worker folders 0 .. $threads - 1 (copies of folder 0) and starts one worker in each. A worker whose
     * previous run is still finishing after a Stop starts once that run has exited (see RunJob).
     * Returns false when the converters are already running.
     *
     * @throws RuntimeException when the converter folder or its base folder "0" is missing, or a worker folder cannot
     *                          be created or is incomplete
     */
    public function start(int $threads): bool
    {
        return $this->status->lock()->block(10, function () use ($threads): bool {
            if ($this->status->current()['status'] !== ConverterStatus::STOPPED) {
                return false;
            }

            // Workers running without having been asked to finish mean that the panel lost its state (for example
            // after cache:clear). Show them as running instead of starting a second worker in their folders.
            $this->workerProcesses->forgetOverview();
            $runningWorkers = $this->workerProcesses->overview()['started'];

            if ($runningWorkers !== []) {
                $this->status->set(ConverterStatus::RUNNING, count($runningWorkers));

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
                } elseif (! File::exists($folder.DIRECTORY_SEPARATOR."e{$index}.exe")) {
                    throw new RuntimeException("The worker folder {$folder} has no e{$index}.exe. Delete the folder so that it is created again from folder 0.");
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
     * Copies the base worker folder and renames its files for worker $index (e0.exe becomes e{index}.exe, ...). The
     * copy is made under a temporary name and renamed once complete, so that a failed copy never leaves a folder that
     * looks like a finished worker.
     */
    private function createWorkerFolder(string $template, string $folder, int $index): void
    {
        $partial = $folder.'.partial';
        File::deleteDirectory($partial);

        try {
            if (! File::copyDirectory($template, $partial)) {
                throw new RuntimeException("Cannot copy {$template} to {$partial}");
            }

            $renames = [
                'e0.exe' => "e{$index}.exe",
                '0.exe' => "{$index}.exe",
                'e0.exe.config' => "e{$index}.exe.config",
            ];

            foreach ($renames as $from => $to) {
                $source = $partial.DIRECTORY_SEPARATOR.$from;

                if (File::exists($source)) {
                    File::move($source, $partial.DIRECTORY_SEPARATOR.$to);
                }
            }

            $configTxt = $partial.DIRECTORY_SEPARATOR.'config.txt';
            if (File::exists($configTxt)) {
                File::put($configTxt, (string) $index);
            }

            // The shared folder in the .NET configuration ends with the base worker's number, for example f:\\0\\.
            $configXml = $partial.DIRECTORY_SEPARATOR."e{$index}.exe.config";
            if (File::exists($configXml)) {
                File::put($configXml, (string) preg_replace(
                    '/(key="SharedFolder"\s+value="[^"]*?(?:\\\\){1,2})0((?:\\\\){1,2}")/',
                    '${1}'.$index.'${2}',
                    File::get($configXml),
                ));
            }

            if (! File::moveDirectory($partial, $folder)) {
                throw new RuntimeException("Cannot rename {$partial} to {$folder}");
            }
        } catch (Throwable $exception) {
            File::deleteDirectory($partial);

            throw new RuntimeException("Cannot create the worker folder {$folder}: {$exception->getMessage()}", previous: $exception);
        }
    }
}
