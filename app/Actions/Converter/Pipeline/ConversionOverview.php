<?php

namespace App\Actions\Converter\Pipeline;

use App\Models\Conversion;
use Illuminate\Support\Collection;

/**
 * What the dashboard shows: how much work is waiting, what is being converted right now, and what
 * failed. All of it comes from the panel's own tables, so the numbers are the truth rather than a
 * guess made from a process list.
 */
class ConversionOverview
{
    /**
     * @return array{waiting: int, converting: int, done: int, failed: int, converted_today: int}
     */
    public function counts(): array
    {
        /** @var Collection<string, int> $byStatus */
        $byStatus = Conversion::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return [
            'waiting' => (int) $byStatus->get(ConversionStatus::Pending->value, 0),
            'converting' => (int) $byStatus->get(ConversionStatus::Claimed->value, 0),
            'done' => (int) $byStatus->get(ConversionStatus::Done->value, 0),
            'failed' => (int) $byStatus->get(ConversionStatus::Failed->value, 0),
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
     * The most recent failures, with the step they failed at - the thing the old pipeline's log made
     * impossible to see.
     *
     * @return Collection<int, Conversion>
     */
    public function failures(int $limit = 15): Collection
    {
        return Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->orderByDesc('finished_at')
            ->limit($limit)
            ->get(['id', 'content_id', 'failure_stage', 'failure_reason', 'attempts', 'finished_at']);
    }
}
