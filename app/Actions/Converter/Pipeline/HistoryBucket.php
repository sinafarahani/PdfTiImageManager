<?php

namespace App\Actions\Converter\Pipeline;

use Carbon\CarbonImmutable;

/**
 * How wide one column of the history chart is.
 *
 * The pattern is the one the database groups by. MySQL's DATE_FORMAT and SQLite's strftime happen to
 * spell these particular fields the same way, so only the function name differs between them - see
 * ConversionHistory::bucketExpression().
 */
enum HistoryBucket: string
{
    case Hour = 'hour';
    case Day = 'day';
    case Month = 'month';

    public function pattern(): string
    {
        return match ($this) {
            self::Hour => '%Y-%m-%d %H:00',
            self::Day => '%Y-%m-%d',
            self::Month => '%Y-%m',
        };
    }

    /**
     * The same key the database produces, for a moment in time. Gaps are filled with these: an hour
     * in which nothing was converted has no row to group, and leaving it out would draw the chart as
     * if that hour had never happened.
     */
    public function keyFor(CarbonImmutable $at): string
    {
        return match ($this) {
            self::Hour => $at->format('Y-m-d H:00'),
            self::Day => $at->format('Y-m-d'),
            self::Month => $at->format('Y-m'),
        };
    }

    public function start(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Hour => $at->startOfHour(),
            self::Day => $at->startOfDay(),
            self::Month => $at->startOfMonth(),
        };
    }

    public function next(CarbonImmutable $at): CarbonImmutable
    {
        return match ($this) {
            self::Hour => $at->addHour(),
            self::Day => $at->addDay(),
            self::Month => $at->addMonth(),
        };
    }

    /**
     * The short label under a column.
     */
    public function labelFor(CarbonImmutable $at): string
    {
        return match ($this) {
            self::Hour => $at->format('H:00'),
            self::Day => $at->format('j M'),
            self::Month => $at->format('M Y'),
        };
    }

    /**
     * The long label a column is named by when it is pointed at.
     */
    public function titleFor(CarbonImmutable $at): string
    {
        return match ($this) {
            self::Hour => $at->format('j M Y, H:00').'-'.$at->addHour()->format('H:00'),
            self::Day => $at->format('l j F Y'),
            self::Month => $at->format('F Y'),
        };
    }
}
