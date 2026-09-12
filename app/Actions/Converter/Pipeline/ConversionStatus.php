<?php

namespace App\Actions\Converter\Pipeline;

/**
 * Where a content stands in the panel's own queue. The archive has no such column - it only knows
 * "reserved" and "converted" - so this is the only place a half-finished conversion is visible, and
 * it is what the reconciler reads to find work a stopped worker left behind.
 */
enum ConversionStatus: string
{
    case Pending = 'pending';
    case Claimed = 'claimed';
    case Done = 'done';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'waiting',
            self::Claimed => 'being converted',
            self::Done => 'converted',
            self::Failed => 'failed',
            self::Cancelled => 'cancelled',
        };
    }

    /**
     * A finished content is never claimed again, whichever way it ended.
     */
    public function isFinished(): bool
    {
        return in_array($this, [self::Done, self::Failed, self::Cancelled], true);
    }
}
