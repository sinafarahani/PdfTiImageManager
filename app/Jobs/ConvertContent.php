<?php

namespace App\Jobs;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\ConvertOneContent;
use App\Models\Conversion;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Converts one content. The queue is only the transport here: retries, attempt counting and the
 * cleanup of a half-finished attempt all live in the conversions table, so that a person can see in
 * the panel why a document keeps coming back instead of reading failed_jobs.
 */
class ConvertContent implements ShouldQueue
{
    use Queueable;

    /**
     * One attempt per job. A content that deserves another go is put back in the queue by
     * ConversionQueue::fail(), which counts the attempts.
     */
    public int $tries = 1;

    /**
     * Windows has no pcntl, so a queue worker's --timeout does nothing. The time limit that does
     * work lives on the pdf2img child process (converter.render.timeout).
     */
    public int $timeout = 0;

    public function __construct(public readonly int $conversionId) {}

    public function handle(ConvertOneContent $pipeline): void
    {
        $conversion = Conversion::query()->find($this->conversionId);

        if ($conversion === null) {
            return;
        }

        // The dispatcher claims a conversion and then queues this job, so anything else means the
        // job outlived its claim: the reconciler took it back, or someone cancelled it.
        if ($conversion->status !== ConversionStatus::Claimed) {
            Log::info("Conversion {$conversion->id} is {$conversion->status->value}, not converting it again.");

            return;
        }

        $pipeline->convert($conversion);
    }
}
