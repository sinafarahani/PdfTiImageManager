<?php

namespace App\Livewire;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionOverview;
use App\Models\Conversion;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Validate;
use Livewire\Component;

class Action extends Component
{
    /**
     * One of the ConverterStatus states: stopped or running. It decides between the Start and the
     * Stop button, and whether the dispatcher hands out new contents.
     */
    #[Locked]
    public string $status = ConverterStatus::STOPPED;

    /**
     * Contents converted at the same time, as set by the last Start.
     */
    #[Locked]
    public int $runningThreads = 0;

    /**
     * Version of the shared state this browser shows; it changes whenever anyone starts or stops.
     */
    #[Locked]
    public int $version = 0;

    /**
     * Live counts from the conversions table.
     *
     * @var array{waiting: int, converting: int, done: int, missing: int, skipped: int, failed: int, converted_today: int}
     */
    #[Locked]
    public array $counts = ['waiting' => 0, 'converting' => 0, 'done' => 0, 'missing' => 0, 'skipped' => 0, 'failed' => 0, 'converted_today' => 0];

    /**
     * The last few failures, so the step and the reason the pipeline records are actually visible.
     *
     * @var list<array{content: string, stage: string, reason: string, when: string}>
     */
    #[Locked]
    public array $recentFailures = [];

    /**
     * Number of contents to convert at the same time (the input field).
     */
    #[Validate('required|integer|min:1|max:64', onUpdate: false)]
    public $threads = 4;

    public function mount(ConverterStatus $converterStatus, ConversionOverview $overview): void
    {
        $this->showState($converterStatus->current());
        $this->showWork($overview, $overview->counts());
    }

    /**
     * Polled every few seconds. Shows a start or stop made in another browser and the conversions
     * that finished meanwhile, and otherwise leaves the page alone. A number being typed is never
     * reset, because the input is only overwritten when the shared state itself changed.
     */
    public function syncState(ConverterStatus $converterStatus, ConversionOverview $overview): void
    {
        $state = $converterStatus->current();
        $counts = $overview->counts();
        $stateChanged = $state['version'] !== $this->version || $state['status'] !== $this->status;

        if (! $stateChanged && $counts === $this->counts) {
            $this->skipRender();

            return;
        }

        if ($stateChanged) {
            $this->resetErrorBag();
            $this->showState($state);
        }

        $this->showWork($overview, $counts);
    }

    /**
     * @param  array{waiting: int, converting: int, done: int, missing: int, skipped: int, failed: int, converted_today: int}  $counts
     */
    private function showWork(ConversionOverview $overview, array $counts): void
    {
        $this->counts = $counts;

        // The failure list costs a query, so it is only fetched when there is something in it.
        $this->recentFailures = $counts['failed'] === 0 ? [] : $overview->failures(5)
            ->map(fn (Conversion $conversion): array => [
                'content' => $conversion->content_id,
                'stage' => $conversion->failure_stage?->label() ?? 'unknown',
                'reason' => (string) $conversion->failure_reason,
                'when' => $conversion->finished_at?->diffForHumans() ?? '',
            ])
            ->all();
    }

    /**
     * Start converting. Nothing is copied, renamed or launched: the dispatcher is simply allowed to
     * hand contents to the workers again, so this is instant.
     */
    public function start(ConverterStatus $converterStatus): void
    {
        Gate::authorize('start-action');
        $this->validate();

        try {
            $converterStatus->lock()->block(10, function () use ($converterStatus): void {
                $converterStatus->set(ConverterStatus::RUNNING, (int) $this->threads);
            });
        } catch (LockTimeoutException) {
            $this->addError('threads', __('Another start or stop is in progress. Try again in a moment.'));

            return;
        }

        $this->showState($converterStatus->current());
    }

    /**
     * Stop converting: no content is taken on from now, and the ones being converted finish
     * normally. Nothing is ever interrupted, so Start can be pressed again straight away.
     */
    public function stop(ConverterStatus $converterStatus): void
    {
        Gate::authorize('start-action');

        try {
            $converterStatus->lock()->block(10, function () use ($converterStatus): void {
                $converterStatus->set(ConverterStatus::STOPPED);
            });
        } catch (LockTimeoutException) {
            $this->addError('threads', __('Another start or stop is in progress. Try again in a moment.'));

            return;
        }

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
