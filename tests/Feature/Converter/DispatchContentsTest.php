<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Jobs\ConvertContent;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class DispatchContentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Sleep::fake();
        config([
            'converter.queue.name' => 'conversions',
            'converter.queue.queued_per_worker' => 2,
        ]);
    }

    public function test_it_hands_out_nothing_while_the_panel_says_stopped(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::STOPPED);
        $conversion = $this->pending();

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        // Stop means no new content is taken; the ones already running are not touched.
        Queue::assertNothingPushed();
        $this->assertSame(ConversionStatus::Pending, $conversion->refresh()->status);
    }

    public function test_it_claims_the_waiting_contents_and_queues_one_job_for_each(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        $conversions = collect(range(1, 3))->map(fn (): Conversion => $this->pending());

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        Queue::assertPushedOn('conversions', ConvertContent::class);
        Queue::assertPushed(ConvertContent::class, 3);

        foreach ($conversions as $conversion) {
            $conversion->refresh();
            $this->assertSame(ConversionStatus::Claimed, $conversion->status);
            $this->assertNotNull($conversion->worker);
        }
    }

    public function test_it_hands_out_no_more_than_the_running_workers_can_hold(): void
    {
        // One worker, two queued per worker: at most two contents are ever outstanding, so a Stop
        // never leaves a long tail of claimed work behind.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);
        collect(range(1, 5))->each(fn (): Conversion => $this->pending());

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ConvertContent::class, 2);
        $this->assertSame(2, Conversion::query()->claimed()->count());
        $this->assertSame(3, Conversion::query()->pending()->count());
    }

    public function test_it_waits_instead_of_asking_again_when_nothing_is_waiting(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        Queue::assertNothingPushed();
        Sleep::assertSlept(fn (): bool => true);
    }

    private function pending(): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Pending,
        ]);
    }
}
