<?php

namespace App\Console\Commands;

use App\Actions\Converter\WorkerProcesses;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class EndFrozenWorkers extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:end-frozen';

    /**
     * @var string
     */
    protected $description = 'End workers that are still running long after they were asked to finish (frozen)';

    public function handle(WorkerProcesses $workerProcesses): int
    {
        $root = rtrim((string) config('app.pdfToImg'), '\\/');

        foreach (File::isDirectory($root) ? File::directories($root) : [] as $folder) {
            if ($workerProcesses->endIfFrozen($folder)) {
                $this->warn('Ended frozen worker '.basename($folder));
            }
        }

        return self::SUCCESS;
    }
}
