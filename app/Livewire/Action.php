<?php

namespace App\Livewire;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\StartConverters;
use App\Actions\Converter\StopConverters;
use App\Actions\Converter\WorkerProcesses;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

class Action extends Component
{
    /**
     * One of the ConverterStatus states: stopped or running. It decides between the Start and the Stop button.
     */
    #[Locked]
    public string $status = ConverterStatus::STOPPED;

    /**
     * Number of processes of the current (or last) run.
     */
    #[Locked]
    public int $runningThreads = 0;

    /**
     * Version of the shared state this browser shows; it changes whenever anyone starts or stops.
     */
    #[Locked]
    public int $version = 0;

    /**
     * Workers whose process is running and that were not asked to finish.
     */
    #[Locked]
    public int $startedWorkers = 0;

    /**
     * Workers whose process is still running after a Stop (finishing their current file).
     */
    #[Locked]
    public int $stoppingWorkers = 0;

    /**
     * Number of processes to start (the input field).
     */
    #[Validate('required|integer|min:1|max:64', onUpdate: false)]
    public $threads = 4;

    public function mount(ConverterStatus $converterStatus, WorkerProcesses $workerProcesses): void
    {
        $this->showState($converterStatus->current());
        $this->showWorkers($workerProcesses->overview());
    }

    /**
     * Polled every few seconds. Shows a start or stop made in another browser and workers that have started or
     * stopped, and otherwise leaves the page alone. A process count that is being typed is never reset.
     */
    public function syncState(ConverterStatus $converterStatus, WorkerProcesses $workerProcesses): void
    {
        $state = $converterStatus->current();
        $overview = $workerProcesses->overview();
        $stateChanged = $state['version'] !== $this->version || $state['status'] !== $this->status;
        $workersChanged = count($overview['started']) !== $this->startedWorkers
            || count($overview['stopping']) !== $this->stoppingWorkers;

        if (! $stateChanged && ! $workersChanged) {
            $this->skipRender();

            return;
        }

        if ($stateChanged) {
            $this->showState($state);
        }

        $this->showWorkers($overview);
    }

    public function start(StartConverters $startConverters, ConverterStatus $converterStatus, WorkerProcesses $workerProcesses): void
    {
        Gate::authorize('start-action');
        $this->validate();

        try {
            $started = $startConverters->start((int) $this->threads);
        } catch (RuntimeException $exception) {
            report($exception);
            $this->addError('threads', $exception->getMessage());

            return;
        }

        $this->showState($converterStatus->current());
        $this->showWorkers($workerProcesses->overview());

        if (! $started) {
            $this->addError('threads', __('The converters are already running.'));
        }
    }

    public function stop(StopConverters $stopConverters, ConverterStatus $converterStatus, WorkerProcesses $workerProcesses): void
    {
        Gate::authorize('start-action');

        $stopConverters->stop();

        $this->showState($converterStatus->current());
        $this->showWorkers($workerProcesses->overview());
    }

    public function render(): View
    {
        return view('livewire.action');
    }

    /**
     * @param  array{status: string, threads: int, version: int}  $state
     */
    private function showState(array $state): void
    {
        $this->status = $state['status'];
        $this->runningThreads = $state['threads'];
        $this->threads = $state['threads'];
        $this->version = $state['version'];
    }

    /**
     * @param  array{started: list<int>, stopping: list<int>}  $overview
     */
    private function showWorkers(array $overview): void
    {
        $this->startedWorkers = count($overview['started']);
        $this->stoppingWorkers = count($overview['stopping']);
    }
}
