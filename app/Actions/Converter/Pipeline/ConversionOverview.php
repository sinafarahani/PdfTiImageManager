<?php

namespace App\Actions\Converter\Pipeline;

use App\Models\Conversion;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What the dashboard shows: how much work is waiting, what is being converted right now, and what
 * failed. All of it comes from the panel's own tables, so the numbers are the truth rather than a
 * guess made from a process list.
 */
class ConversionOverview
{
    /**
     * The four numbers on the dashboard, and today's total.
     *
     * One grouped count rather than four counts, because the dashboard polls this every two seconds
     * and conversions is a table of half a million rows: the group by is answered from the
     * [status, id] index without reading the table, and "converted today" from [status, finished_at],
     * which is the index the second migration adds for exactly this query. It is still a scan of one
     * index per poll, which is fine at this size and would not be at ten times it - if this table is
     * ever kept for years rather than pruned, the four numbers belong in a counters row.
     *
     * @return array{waiting: int, converting: int, done: int, missing: int, skipped: int, failed: int, converted_today: int}
     */
    public function counts(): array
    {
        // Grouped by the step as well as the status, in the same one query, because a content whose
        // source file the archive lists but no longer has is not a failure of this pipeline: there are
        // thousands of those stale rows, and counting them with the real failures would bury them.
        $grouped = Conversion::query()
            ->selectRaw('status, failure_stage, count(*) as total')
            ->groupBy('status', 'failure_stage')
            ->get();

        /** @var Collection<string, int> $byStatus */
        $byStatus = $grouped->groupBy('status')->map(fn (Collection $rows): int => (int) $rows->sum('total'));

        $missing = (int) $grouped
            ->where('status', ConversionStatus::Failed->value)
            ->where('failure_stage', Stage::Missing->value)
            ->sum('total');

        return [
            'waiting' => (int) $byStatus->get(ConversionStatus::Pending->value, 0),
            'converting' => (int) $byStatus->get(ConversionStatus::Claimed->value, 0),
            'done' => (int) $byStatus->get(ConversionStatus::Done->value, 0),
            'missing' => $missing,

            // Contents of a profile that is not converted. They are their own status rather than a
            // failure, so they are already outside every other number here; the tile exists so that a
            // queue that suddenly drops by a few hundred thousand says where they went.
            'skipped' => (int) $byStatus->get(ConversionStatus::Cancelled->value, 0),
            'failed' => (int) $byStatus->get(ConversionStatus::Failed->value, 0) - $missing,
            'converted_today' => Conversion::query()
                ->where('status', ConversionStatus::Done)
                ->where('finished_at', '>=', now()->startOfDay())
                ->count(),
        ];
    }

    /**
     * The contents being converted at this moment, oldest claim first.
     *
     * @return Collection<int, Conversion>
     */
    public function converting(int $limit = 10): Collection
    {
        return Conversion::query()
            ->claimed()
            ->orderBy('claimed_at')
            ->limit($limit)
            ->get(['id', 'content_id', 'worker', 'claimed_at', 'heartbeat_at', 'attempts']);
    }

    /**
     * Everything that did not convert, of one kind, newest first - the full list behind the tiles.
     *
     * The search is a plain "contains" over the content id and the recorded reason, because the
     * reason is where the file path is and looking a document up by its path is the whole point of
     * having the list. It is not indexable and it does not need to be: this is a page somebody opens
     * to investigate, not something the pipeline runs.
     *
     * @return Builder<Conversion>
     */
    public function problems(FailureKind $kind, string $search = ''): Builder
    {
        $query = $kind->constrain(Conversion::query())->orderByDesc('finished_at')->orderByDesc('id');

        if (($term = trim($search)) !== '') {
            $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $term).'%';

            $query->where(function (Builder $matching) use ($like): void {
                $matching->where('content_id', 'like', $like)->orWhere('failure_reason', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * How many there are of each kind, for the tabs above the list.
     *
     * @return array<string, int>
     */
    public function problemCounts(): array
    {
        $counts = [];

        foreach (FailureKind::cases() as $kind) {
            $counts[$kind->value] = $kind->constrain(Conversion::query())->count();
        }

        return $counts;
    }

    /**
     * The most recent failures, with the step they failed at - the thing the old pipeline's log made
     * impossible to see.
     *
     * @return Collection<int, Conversion>
     */
    public function failures(int $limit = 15): Collection
    {
        return Conversion::query()
            ->where('status', ConversionStatus::Failed)

            // Contents whose source file the archive no longer has are counted on their own tile and
            // kept out of here. There are thousands of them, they all say the same thing, and with
            // them in the list the failures actually worth reading are never on screen.
            ->where(function (Builder $query): void {
                $query->whereNull('failure_stage')->orWhere('failure_stage', '!=', Stage::Missing->value);
            })
            ->orderByDesc('finished_at')
            ->limit($limit)
            ->get(['id', 'content_id', 'failure_stage', 'failure_reason', 'attempts', 'finished_at']);
    }
}
