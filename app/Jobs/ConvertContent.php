<?php

namespace App\Jobs;

use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\ConvertOneContent;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

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

    /**
     * @param  string|null  $claimedAt  the claim this job was queued for, as the conversion's own
     *                                  claimed_at. It is what tells this job apart from the claim
     *                                  that is current now, and a job queued by an older version of
     *                                  the panel simply has none.
     */
    public function __construct(
        public readonly int $conversionId,
        public readonly ?string $claimedAt = null,
    ) {}

    public function handle(Container $container, ConversionQueue $queue): void
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

        // Claimed, but claimed *again*: the reconciler put this content back and the dispatcher has
        // since handed it to another worker, which is already converting it. Without this, a job that
        // sat in the queue through all of that would pick the row up as though the new claim were its
        // own - and the first thing it does on a failure is hand the content back, which would take
        // it off the worker that has it. The queue never re-delivers a job (tries = 1), so the only
        // way a job gets here late is that nothing ran it for at least
        // converter.failure.stale_after_minutes.
        $claim = $conversion->claimed_at?->toDateTimeString();

        if ($this->claimedAt !== null && $claim !== $this->claimedAt) {
            Log::warning(sprintf(
                'Conversion %d was claimed again at %s; this job was queued for the claim of %s and is not converting it.',
                $conversion->id,
                $claim ?? 'no time at all',
                $this->claimedAt,
            ));

            return;
        }

        // Building the pipeline reaches the archive: the file store is bound from the FTP site the
        // archive names. When the archive is not answering, that happens before convert() and its
        // failure handling, so the failure is recorded here instead - otherwise the conversion would
        // stay "converting" in the panel, with attempts still at zero, until it went stale.
        try {
            $pipeline = $container->make(ConvertOneContent::class);
        } catch (Throwable $exception) {
            report($exception);
            $queue->fail($conversion, Stage::Reserve, 'the converter could not start: '.Str::limit($exception->getMessage(), 300), retryable: true);

            return;
        }

        $pipeline->convert($conversion);
    }
}
