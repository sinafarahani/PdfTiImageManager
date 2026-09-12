<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Jobs\ConvertContent;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
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

    public function test_a_job_nobody_will_ever_run_does_not_use_up_a_slot_for_ever(): void
    {
        // config/queue.php sets retry_after to ten years, so a job whose worker was killed stays in
        // the jobs table, reserved, for ever. Measuring the outstanding work by the queue's depth
        // would count it every time: a few of those and the dispatcher would hand out nothing again,
        // silently. A claimed conversion is the real measure of "handed out and not finished", and
        // the reconciler is what clears the ones nobody is working on.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);

        // Two jobs sitting in the queue whose conversions are no longer claimed: nothing will ever
        // run them, and they fill the whole capacity of one worker.
        ConvertContent::dispatch(9001)->onQueue('conversions');
        ConvertContent::dispatch(9002)->onQueue('conversions');
        $this->pending();
        $this->pending();

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        $this->assertSame(2, Conversion::query()->claimed()->count());
        Queue::assertPushed(ConvertContent::class, 4);
    }

    public function test_it_stops_handing_out_work_while_the_machine_itself_is_failing(): void
    {
        // An FTP site that is down or a full staging drive fails every content it is given, three
        // attempts each. Half a million contents are waiting, so a dispatcher that kept feeding them
        // in would mark the whole archive failed within a day. A damaged PDF is not this: it fails at
        // "render", which is a verdict about the document and is deliberately not counted here.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);

        foreach (range(1, 6) as $ignored) {
            $this->failed(Stage::Upload);
        }

        $this->pending();

        $this->artisan('converters:dispatch', ['--once' => true])
            ->expectsOutputToContain('handing out nothing until that clears')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, Conversion::query()->pending()->count());
    }

    public function test_documents_the_renderer_refuses_do_not_stop_the_dispatcher(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);

        foreach (range(1, 20) as $ignored) {
            $this->failed(Stage::Render);
        }

        $this->pending();

        $this->artisan('converters:dispatch', ['--once' => true])->assertSuccessful();

        Queue::assertPushed(ConvertContent::class, 1);
    }

    public function test_a_second_dispatcher_stands_by_instead_of_handing_out_work_as_well(): void
    {
        // Two dispatchers claim twice as much work as the workers can convert, and the surplus sits
        // claimed until it goes stale and costs the content an attempt. A supervisor that was killed
        // leaves its dispatcher running, so this has to be handled rather than assumed away.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        Cache::put('converter.dispatcher', 'dispatcher@another-host:123', 300);
        $this->pending();

        $this->artisan('converters:dispatch', ['--once' => true])
            ->expectsOutputToContain('standing by')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertSame(1, Conversion::query()->pending()->count());
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

    private function failed(Stage $stage): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Failed,
            'attempts' => 3,
            'failure_stage' => $stage,
            'failure_reason' => 'the site is not answering',
            'finished_at' => now()->subMinute(),
        ]);
    }
}
