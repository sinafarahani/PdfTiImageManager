<?php

namespace App\Actions\Converter;

use Illuminate\Support\Facades\File;

class StopConverters
{
    public function __construct(
        private ConverterStatus $status,
        private WorkerProcesses $workerProcesses,
    ) {}

    /**
     * Asks every worker to finish (status.txt = "terminate"): the bridge ends it at a safe point, never in the middle
     * of a conversion. The panel shows "stopped" right away so that Start can be clicked at once; a worker whose
     * previous run is still finishing starts again once that run has exited (see RunJob). A worker that is still
     * running long after the request is frozen and is ended by force (see WorkerProcesses::endIfFrozen).
     * Returns false when the converters are not running.
     */
    public function stop(): bool
    {
        return $this->status->lock()->block(10, function (): bool {
            if ($this->status->current()['status'] !== ConverterStatus::RUNNING) {
                return false;
            }

            $root = rtrim((string) config('app.pdfToImg'), '\\/');

            foreach (File::isDirectory($root) ? File::directories($root) : [] as $folder) {
                // A pending request keeps its time: the frozen-worker timeout counts from the first Stop.
                if (! $this->workerProcesses->stopIsPending($folder)) {
                    File::put($folder.DIRECTORY_SEPARATOR.'status.txt', 'terminate');
                }
            }

            $this->workerProcesses->forgetOverview();
            $this->status->set(ConverterStatus::STOPPED);

            return true;
        });
    }
}
