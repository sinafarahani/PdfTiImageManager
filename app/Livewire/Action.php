<?php

namespace App\Livewire;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\StartConverters;
use App\Actions\Converter\StopConverters;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;
use RuntimeException;

class Action extends Component
{
    /**
     * One of the ConverterStatus states: stopped or running.
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
     * Number of processes to start (the input field).
     */
    #[Validate('required|integer|min:1|max:64', onUpdate: false)]
    public $threads = 4;

    public function mount(ConverterStatus $converterStatus): void
    {
        $this->showState($converterStatus->current());
    }

    /**
     * Polled every few seconds. Shows a start or stop made in another browser, and otherwise leaves the page alone,
     * including a process count that is being typed.
     */
    public function syncState(ConverterStatus $converterStatus): void
    {
        $state = $converterStatus->current();

        if ($state['version'] === $this->version && $state['status'] === $this->status) {
            $this->skipRender();

            return;
        }

        $this->showState($state);
    }

    public function start(StartConverters $startConverters, ConverterStatus $converterStatus): void
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

        if (! $started) {
            $this->addError('threads', __('The converters are already running.'));
        }
    }

    public function stop(StopConverters $stopConverters, ConverterStatus $converterStatus): void
    {
        Gate::authorize('start-action');

        $stopConverters->stop();

        $this->showState($converterStatus->current());
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
}
