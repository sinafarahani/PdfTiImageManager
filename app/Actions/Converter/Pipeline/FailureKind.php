<?php

namespace App\Actions\Converter\Pipeline;

use App\Models\Conversion;
use Illuminate\Database\Eloquent\Builder;

/**
 * The kinds of content that did not convert, which are worth reading apart from one another.
 *
 * Most of them are not failures of this pipeline at all: a source file the archive no longer has, or
 * a profile nobody converts any more. There are hundreds of thousands of those and they all say the
 * same thing, so a list with them in it never shows the handful that need looking at.
 */
enum FailureKind: string
{
    case Failures = 'failures';
    case Missing = 'missing';
    case Skipped = 'skipped';
    case All = 'all';

    public function label(): string
    {
        return match ($this) {
            self::Failures => 'Failed',
            self::Missing => 'No source file',
            self::Skipped => 'Not converted',
            self::All => 'Everything',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Failures => 'Conversions that went wrong. These are the ones worth reading.',
            self::Missing => 'The archive names a PDF the file store does not have. Nothing to fix here.',
            self::Skipped => 'Contents of a profile named in CONVERTER_SKIP_PROFILES.',
            self::All => 'Everything that did not convert, whatever the reason.',
        };
    }

    /**
     * @param  Builder<Conversion>  $query
     * @return Builder<Conversion>
     */
    public function constrain(Builder $query): Builder
    {
        return match ($this) {
            self::Failures => $query
                ->where('status', ConversionStatus::Failed)
                ->where(function (Builder $failures): void {
                    $failures->whereNull('failure_stage')->orWhere('failure_stage', '!=', Stage::Missing->value);
                }),
            self::Missing => $query
                ->where('status', ConversionStatus::Failed)
                ->where('failure_stage', Stage::Missing->value),
            self::Skipped => $query->where('status', ConversionStatus::Cancelled),
            self::All => $query->whereIn('status', [ConversionStatus::Failed, ConversionStatus::Cancelled]),
        };
    }
}
