<?php

namespace App\Livewire;

use App\Console\Commands\MonitorFreeze;
use App\Console\Commands\SaveAllIDs;
use App\Jobs\RunJob;
use App\Jobs\TerminateJob;
use App\Models\LastStop;
use Illuminate\Contracts\Filesystem\FileNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schedule;
use Livewire\Component;
use Log;

class Action extends Component
{
    public $threads;
    public $started;

    public function render()
    {
        // Refresh state every render
        $this->started = Cache::get('action_started', false);
        $this->threads = Cache::get('action_threads', 4);

        return view('livewire.action');
    }

    public function mount(): void
    {
        $this->started = Cache::get('action_started', false);
        $this->threads = Cache::get('action_threads', 4);
    }

    /**
     * @throws FileNotFoundException
     */
    public function start(): void
    {
        if (!Gate::allows('start-action')) {
            abort(403, 'Unauthorized');
        }

        if (Cache::get('action_started')) {
            return; // already started, block duplicate
        }

        // Save to cache
        Cache::put('action_started', true);
        Cache::put('action_threads', $this->threads);

        $this->started = true;

        $path = config('app.pdfToImg');
        $runnerPath = config('app.runnerPath');
        $directories = File::directories($path);

        $baseFolderDir = $directories[0];
        $baseFolderName = basename($baseFolderDir);

        while(count($directories)<$this->threads){
            $c = count($directories);
            $destinationPath = $path . DIRECTORY_SEPARATOR . $c;
            File::copyDirectory($directories[0], $destinationPath);
            $renameMap = [
                "e{$baseFolderName}.exe"        => "e{$c}.exe",
                "{$baseFolderName}.exe"         => "{$c}.exe",
                "e{$baseFolderName}.exe.config" => "e{$c}.exe.config",
            ];

            foreach ($renameMap as $old => $new) {
                $oldPath = $destinationPath . DIRECTORY_SEPARATOR . $old;
                $newPath = $destinationPath . DIRECTORY_SEPARATOR . $new;

                if (File::exists($oldPath)) {
                    File::move($oldPath, $newPath);
                }
            }

            // 3. Overwrite config.txt with new index
            $configTxtPath = $destinationPath . DIRECTORY_SEPARATOR . 'config.txt';
            if (File::exists($configTxtPath)) {
                File::put($configTxtPath, (string) $c);
            }

            // 4. Update SharedFolder in exe.config
            $configXmlPath = $destinationPath . DIRECTORY_SEPARATOR . "e{$c}.exe.config";
            if (File::exists($configXmlPath)) {
                $xmlContent = File::get($configXmlPath);
                $updatedXml = str_replace(
                    "f:\\\\{$baseFolderName}\\\\",
                    "f:\\\\{$c}\\\\",
                    $xmlContent
                );
                File::put($configXmlPath, $updatedXml);
            }

            $directories = File::directories($path);
        }
        $i = 0;
        foreach ($directories as $dirPath) {
            if($i>=$this->threads){
                break;
            }
            // Extract the index from the folder name
            RunJob::dispatch($dirPath, $this->threads, $runnerPath);
            $i++;
        }

        $this->dispatch('refresh-livewire');

        $this->redirectRoute('dashboard', [
            'started' => true,
            'threads' => $this->threads,
        ]);
    }

    public function stop(): void
    {
        if (!Gate::allows('start-action')) {
            abort(403, 'Unauthorized');
        }

        if (!Cache::get('action_started')) {
            return; // already stopped
        }

        Cache::put('action_started', false);

        $basePath = config('app.pdfToImg');
        $directories = File::directories($basePath);

        foreach ($directories as $dirPath) {
            $statusFile = $dirPath . DIRECTORY_SEPARATOR . 'status.txt';

            File::put($statusFile, 'terminate');

            $baseDirPath = basename($dirPath);

            TerminateJob::dispatch($baseDirPath);
        }

        $this->dispatch('refresh-livewire');

        $this->redirectRoute('dashboard', [
            'started' => false
        ]);
    }
}
