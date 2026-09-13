<?php

namespace App\Livewire;

use App\Actions\Converter\Pipeline\ConversionOverview;
use App\Actions\Converter\Pipeline\FailureKind;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The whole list of contents that did not convert, rather than the five most recent.
 *
 * The reason a failure records holds the path of the file it was working on, which is what makes the
 * list worth having: a document can be looked at on the file store straight from what is on screen.
 */
class Failures extends Component
{
    use WithPagination;

    #[Url(as: 'show', keep: false)]
    public string $kind = FailureKind::Failures->value;

    #[Url(as: 'q', keep: false)]
    public string $search = '';

    /**
     * Rows per page.
     */
    public const PER_PAGE = 25;

    public function show(string $kind): void
    {
        if (FailureKind::tryFrom($kind) !== null) {
            $this->kind = $kind;
            $this->resetPage();
        }
    }

    /**
     * Back to the first page whenever the search changes: page 7 of the old results is not page 7 of
     * the new ones, and staying there shows an empty list for a search that matched plenty.
     */
    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render(ConversionOverview $overview): View
    {
        $kind = FailureKind::tryFrom($this->kind) ?? FailureKind::Failures;

        return view('livewire.failures', [
            'kinds' => FailureKind::cases(),
            'selected' => $kind,
            'counts' => $overview->problemCounts(),
            'conversions' => $overview->problems($kind, $this->search)->paginate(self::PER_PAGE),
        ]);
    }
}
