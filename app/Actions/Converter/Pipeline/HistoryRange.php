<?php

namespace App\Actions\Converter\Pipeline;

use Carbon\CarbonImmutable;

/**
 * The stretch of time the history chart covers, and how finely it is cut.
 *
 * A range decides its own columns rather than letting the two be chosen separately: an hour-by-hour
 * chart of a year is 8,760 columns nobody can read, and a month-by-month chart of today is one.
 */
enum HistoryRange: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Day => '24 hours',
            self::Week => '7 days',
            self::Month => '30 days',
            self::All => 'All',
        };
    }

    /**
     * What the chart is of, spelled out under the heading.
     */
    public function description(): string
    {
        return match ($this) {
            self::Day => 'Contents converted per hour, over the last 24 hours.',
            self::Week => 'Contents converted per day, over the last 7 days.',
            self::Month => 'Contents converted per day, over the last 30 days.',
            self::All => 'Contents converted since the panel took over.',
        };
    }

    /**
     * The first moment the chart shows, or null for everything there is.
     */
    public function since(CarbonImmutable $now): ?CarbonImmutable
    {
        return match ($this) {
            // 23 hours back and then to the top of that hour: the hour in progress is the 24th.
            self::Day => $now->subHours(23)->startOfHour(),
            self::Week => $now->subDays(6)->startOfDay(),
            self::Month => $now->subDays(29)->startOfDay(),
            self::All => null,
        };
    }

    /**
     * How wide one column is. "All" is the only one that cannot know in advance: a panel running for
     * a fortnight is read by the day, one running for three years by the month, and the changeover is
     * simply the point where the columns would stop being distinguishable.
     */
    public function bucket(?CarbonImmutable $earliest, CarbonImmutable $now): HistoryBucket
    {
        return match ($this) {
            self::Day => HistoryBucket::Hour,
            self::Week, self::Month => HistoryBucket::Day,
            self::All => $earliest === null || $earliest->diffInDays($now) <= self::DAYS_BEFORE_MONTHS
                ? HistoryBucket::Day
                : HistoryBucket::Month,
        };
    }

    /**
     * Days of history after which "All" is drawn month by month instead of day by day.
     */
    private const DAYS_BEFORE_MONTHS = 120;
}
