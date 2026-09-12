<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\ConvertOneContent;
use App\Actions\Converter\Pipeline\Stage;
use App\Jobs\ConvertContent;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * The job is only the transport, and its whole job is to decide whether the claim it was queued for
 * still exists. A queue job is never re-delivered here (tries = 1), so a job that runs late ran late
 * because nothing picked it up - and by then the reconciler may have handed the content to somebody
 * else, whose conversion this one must not touch.
 */
class ConvertContentJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_the_conversion_it_was_queued_for(): void
    {
        $conversion = $this->claimed('worker-1');
        $pipeline = $this->recordingPipeline();

        (new ConvertContent($conversion->id, $conversion->claimed_at?->toDateTimeString()))->handle($this->app, app(ConversionQueue::class));

        $this->assertSame([$conversion->id], $pipeline->converted);
    }

    public function test_it_leaves_a_conversion_that_was_claimed_again_alone(): void
    {
        // What the reconciler and the dispatcher between them do to a content nobody worked on: the
        // claim this job was queued for is gone and another worker has the content now.
        $conversion = $this->claimed('worker-1');
        $queuedFor = $conversion->claimed_at?->toDateTimeString();

        $conversion->forceFill([
            'worker' => 'worker-2',
            'claimed_at' => now()->addHours(2),
            'heartbeat_at' => now()->addHours(2),
        ])->save();

        $pipeline = $this->recordingPipeline();

        (new ConvertContent($conversion->id, $queuedFor))->handle($this->app, app(ConversionQueue::class));

        $this->assertSame([], $pipeline->converted);
    }

    public function test_it_leaves_a_conversion_that_is_no_longer_claimed_alone(): void
    {
        $conversion = $this->claimed('worker-1');
        $queuedFor = $conversion->claimed_at?->toDateTimeString();
        $conversion->forceFill(['status' => ConversionStatus::Pending, 'worker' => null])->save();

        $pipeline = $this->recordingPipeline();

        (new ConvertContent($conversion->id, $queuedFor))->handle($this->app, app(ConversionQueue::class));

        $this->assertSame([], $pipeline->converted);
    }

    public function test_a_job_from_an_older_version_of_the_panel_still_runs(): void
    {
        $conversion = $this->claimed('worker-1');
        $pipeline = $this->recordingPipeline();

        (new ConvertContent($conversion->id))->handle($this->app, app(ConversionQueue::class));

        $this->assertSame([$conversion->id], $pipeline->converted);
    }

    private function claimed(string $worker): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Claimed,
            'worker' => $worker,
            'claimed_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    public function test_a_pipeline_that_cannot_even_be_built_fails_the_conversion(): void
    {
        // Building the pipeline reaches the archive, because the file store is bound from the FTP
        // site the archive names. That happens before convert() and its failure handling, so an
        // archive that is not answering would otherwise leave the conversion "converting" in the
        // panel, with no attempt counted, until it went stale.
        $conversion = $this->claimed('worker-1');
        $this->app->bind(ConvertOneContent::class, function (): never {
            throw new RuntimeException('the archive is not answering');
        });

        (new ConvertContent($conversion->id, $conversion->claimed_at?->toDateTimeString()))
            ->handle($this->app, app(ConversionQueue::class));

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(1, $conversion->attempts);
        $this->assertSame(Stage::Reserve, $conversion->failure_stage);
        $this->assertStringContainsString('could not start', (string) $conversion->failure_reason);
    }

    /**
     * The pipeline, replaced by something that only says whether it was asked to convert. The
     * constructor is deliberately not called: its collaborators are never reached.
     */
    private function recordingPipeline(): ConvertOneContent
    {
        $pipeline = new class extends ConvertOneContent
        {
            /**
             * @var list<int>
             */
            public array $converted = [];

            public function __construct() {}

            public function convert(Conversion $conversion): void
            {
                $this->converted[] = $conversion->id;
            }
        };

        // The job builds the pipeline through the container rather than taking it as an argument, so
        // that an archive which is not answering - the file store is bound from a query against it -
        // is recorded as a failure of the conversion instead of escaping into failed_jobs.
        $this->app->instance(ConvertOneContent::class, $pipeline);

        return $pipeline;
    }
}
