<?php

namespace App\Actions\Converter\Archive;

use Carbon\CarbonImmutable;

/**
 * A content the archive still needs converted, as found by discovery.
 */
final readonly class DiscoveredContent
{
    public function __construct(
        public string $contentId,
        public ?int $profileId,
        public ?CarbonImmutable $processDate,
    ) {}
}
