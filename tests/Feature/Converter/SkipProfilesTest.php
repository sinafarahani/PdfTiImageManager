<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Pipeline\ConversionOverview;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkipProfilesTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_does_nothing_when_no_profile_is_named(): void
    {
        config(['converter.skip_profiles' => []]);
        $waiting = $this->queued(65, ConversionStatus::Pending);

        $this->artisan('converters:skip')
            ->expectsOutputToContain('No profiles are configured as not converted')
            ->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $waiting->refresh()->status);
    }

    public function test_it_takes_the_waiting_and_the_already_failed_contents_of_a_dead_profile_out_of_the_queue(): void
    {
        // The failed ones are the point: they are the contents that already spent an FTP session each
        // finding out the file was gone, and they are what the tile is full of.
        config(['converter.skip_profiles' => [65]]);
        $waiting = $this->queued(65, ConversionStatus::Pending);
        $missing = $this->queued(65, ConversionStatus::Failed, Stage::Missing);
        $other = $this->queued(12, ConversionStatus::Pending);

        $this->artisan('converters:skip')
            ->expectsOutputToContain('2 content(s) of profile(s) 65 were taken out of the queue')
            ->assertSuccessful();

        foreach ([$waiting, $missing] as $conversion) {
            $conversion->refresh();
            $this->assertSame(ConversionStatus::Cancelled, $conversion->status);
            $this->assertSame(Stage::Skipped, $conversion->failure_stage);
            $this->assertNotNull($conversion->finished_at);
        }

        // Another profile's work is untouched, and so is anything a worker is holding.
        $this->assertSame(ConversionStatus::Pending, $other->refresh()->status);
    }

    public function test_it_leaves_a_content_a_worker_is_converting_alone(): void
    {
        // Taking the row out from under a worker would leave the archive reserved with nobody to
        // release it. The job recognises the profile itself, so it is finished either way.
        config(['converter.skip_profiles' => [65]]);
        $claimed = $this->queued(65, ConversionStatus::Claimed);

        $this->artisan('converters:skip')->assertSuccessful();

        $this->assertSame(ConversionStatus::Claimed, $claimed->refresh()->status);
    }

    public function test_pretend_counts_them_without_changing_anything(): void
    {
        config(['converter.skip_profiles' => [65]]);
        $waiting = $this->queued(65, ConversionStatus::Pending);

        $this->artisan('converters:skip', ['--pretend' => true])
            ->expectsOutputToContain('1 content(s) would be taken out of the queue')
            ->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $waiting->refresh()->status);
    }

    public function test_a_profile_can_be_named_on_the_command_line_instead(): void
    {
        config(['converter.skip_profiles' => []]);
        $waiting = $this->queued(71, ConversionStatus::Pending);

        $this->artisan('converters:skip', ['--profile' => ['71']])->assertSuccessful();

        $this->assertSame(ConversionStatus::Cancelled, $waiting->refresh()->status);
    }

    public function test_the_skipped_contents_are_counted_on_their_own_and_are_not_failures(): void
    {
        config(['converter.skip_profiles' => [65]]);
        $this->queued(65, ConversionStatus::Pending);
        $this->queued(65, ConversionStatus::Failed, Stage::Missing);
        $this->queued(12, ConversionStatus::Failed, Stage::Upload);

        $this->artisan('converters:skip')->assertSuccessful();

        $counts = app(ConversionOverview::class)->counts();

        $this->assertSame(2, $counts['skipped']);
        $this->assertSame(0, $counts['waiting']);
        $this->assertSame(0, $counts['missing']);
        $this->assertSame(1, $counts['failed']);

        // And they stay out of the list a person reads.
        $this->assertCount(1, app(ConversionOverview::class)->failures());
    }

    public function test_naming_a_profile_by_mistake_can_be_undone_without_touching_the_archive(): void
    {
        config(['converter.skip_profiles' => [65]]);
        $waiting = $this->queued(65, ConversionStatus::Pending);
        $this->artisan('converters:skip')->assertSuccessful();

        $this->artisan('converters:retry', ['--stage' => 'skipped'])
            ->expectsOutputToContain('Put 1 conversion(s) back in the queue.')
            ->assertSuccessful();

        $waiting->refresh();
        $this->assertSame(ConversionStatus::Pending, $waiting->status);
        $this->assertNull($waiting->failure_stage);
    }

    public function test_retrying_everything_does_not_bring_the_skipped_contents_back(): void
    {
        // --all is what somebody runs after an FTP outage. Resurrecting a few hundred thousand
        // contents of a dead profile at the same time would undo the whole point of the setting.
        config(['converter.skip_profiles' => [65]]);
        $skipped = $this->queued(65, ConversionStatus::Pending);
        $failed = $this->queued(12, ConversionStatus::Failed, Stage::Upload);
        $this->artisan('converters:skip')->assertSuccessful();

        $this->artisan('converters:retry', ['--all' => true])->assertSuccessful();

        $this->assertSame(ConversionStatus::Cancelled, $skipped->refresh()->status);
        $this->assertSame(ConversionStatus::Pending, $failed->refresh()->status);
    }

    private function queued(int $profileId, ConversionStatus $status, ?Stage $stage = null): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => $profileId,
            'status' => $status,
            'failure_stage' => $stage,
            'failure_reason' => $stage === null ? null : 'because',
            'worker' => $status === ConversionStatus::Claimed ? 'test' : null,
            'claimed_at' => $status === ConversionStatus::Claimed ? now() : null,
            'heartbeat_at' => $status === ConversionStatus::Claimed ? now() : null,
            'finished_at' => $status->isFinished() ? now() : null,
        ]);
    }
}
