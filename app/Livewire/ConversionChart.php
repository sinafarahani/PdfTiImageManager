<?php

namespace App\Livewire;

use App\Actions\Converter\Pipeline\ConversionHistory;
use App\Actions\Converter\Pipeline\HistoryRange;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * How much has been converted over time.
 *
 * It is a component of its own rather than part of Action so that the status tiles can poll every
 * couple of seconds without this grouped count going with them: it is read when the page is opened,
 * when a range is picked, and once a minute after that.
 */
class ConversionChart extends Component
{
    /**
     * The stretch of time on show. In the address bar, so a range worth looking at can be sent to
     * somebody.
     */
    #[Url(as: 'range', keep: false)]
    public string $range = HistoryRange::Week->value;

    public function show(string $range): void
    {
        // Anything else is simply ignored: the value arrives from the address bar as readily as from
        // a button.
        if (HistoryRange::tryFrom($range) !== null) {
            $this->range = $range;
        }
    }

    public function render(ConversionHistory $history): View
    {
        $range = HistoryRange::tryFrom($this->range) ?? HistoryRange::Week;

        return view('livewire.conversion-chart', [
            'ranges' => HistoryRange::cases(),
            'selected' => $range,
            'chart' => $history->of($range),
        ]);
    }
}
