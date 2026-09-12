<?php

namespace App\Models;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One archive content on its way through the pipeline. The row is created by discovery, claimed by a
 * worker, and ends up done or failed; the pages it wrote are recorded in conversion_pages so that an
 * interrupted conversion can be undone exactly.
 *
 * @property int $id
 * @property string $content_id
 * @property int|null $profile_id
 * @property ConversionStatus $status
 * @property int $attempts
 * @property Stage|null $failure_stage
 * @property string|null $failure_reason
 * @property int|null $pages
 * @property string|null $worker
 * @property Carbon|null $claimed_at
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $finished_at
 */
class Conversion extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'content_id',
        'profile_id',
        'status',
        'attempts',
        'failure_stage',
        'failure_reason',
        'pages',
        'worker',
        'claimed_at',
        'heartbeat_at',
        'finished_at',
    ];

    /**
     * The column defaults, repeated here so that a conversion made in PHP starts in the same state a
     * conversion inserted by discovery does.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => ConversionStatus::Pending->value,
        'attempts' => 0,
    ];

    /**
     * @return HasMany<ConversionPage, $this>
     */
    public function pages(): HasMany
    {
        return $this->hasMany(ConversionPage::class);
    }

    /**
     * @param  Builder<Conversion>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', ConversionStatus::Pending);
    }

    /**
     * @param  Builder<Conversion>  $query
     */
    public function scopeClaimed(Builder $query): void
    {
        $query->where('status', ConversionStatus::Claimed);
    }

    /**
     * @param  Builder<Conversion>  $query
     */
    public function scopeFailed(Builder $query): void
    {
        $query->where('status', ConversionStatus::Failed);
    }

    /**
     * Claimed rows nobody is working on any more: their worker died, was killed by the Stop button or
     * lost the machine, so the heartbeat stopped. claimed_at stands in for a row that never got as far
     * as its first heartbeat.
     *
     * @param  Builder<Conversion>  $query
     */
    public function scopeStale(Builder $query, ?CarbonInterface $before = null): void
    {
        $before ??= now()->subMinutes((int) config('converter.failure.stale_after_minutes'));

        $query->claimed()->where(function (Builder $query) use ($before): void {
            $query->where('heartbeat_at', '<', $before)
                ->orWhere(function (Builder $query) use ($before): void {
                    $query->whereNull('heartbeat_at')->where('claimed_at', '<', $before);
                });
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'profile_id' => 'integer',
            'status' => ConversionStatus::class,
            'attempts' => 'integer',
            'failure_stage' => Stage::class,
            'pages' => 'integer',
            'claimed_at' => 'datetime',
            'heartbeat_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
