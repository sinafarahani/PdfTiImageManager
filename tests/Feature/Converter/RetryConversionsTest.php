<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RetryConversionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_refuses_to_do_anything_without_a_selection(): void
    {
        $failed = $this->failed(Stage::Upload);

        $this->artisan('converters:retry')->assertFailed();

        $this->assertSame(ConversionStatus::Failed, $failed->refresh()->status);
    }

    public function test_all_puts_every_failed_conversion_back_with_a_fresh_attempt_count(): void
    {
        $upload = $this->failed(Stage::Upload);
        $render = $this->failed(Stage::Render);
        $done = $this->done();

        $this->artisan('converters:retry', ['--all' => true])
            ->expectsOutputToContain('Put 2 conversion(s) back in the queue.')
            ->assertSuccessful();

        foreach ([$upload, $render] as $conversion) {
            $conversion->refresh();
            $this->assertSame(ConversionStatus::Pending, $conversion->status);
            $this->assertSame(0, $conversion->attempts);
            $this->assertNull($conversion->failure_stage);
            $this->assertNull($conversion->failure_reason);
            $this->assertNull($conversion->finished_at);
        }

        // A conversion that is not failed is never touched.
        $this->assertSame(ConversionStatus::Done, $done->refresh()->status);
    }

    public function test_a_step_can_be_retried_on_its_own(): void
    {
        // The machine's failures are worth retrying; a PDF the renderer refuses is not.
        $upload = $this->failed(Stage::Upload);
        $render = $this->failed(Stage::Render);

        $this->artisan('converters:retry', ['--stage' => 'upload'])->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $upload->refresh()->status);
        $this->assertSame(ConversionStatus::Failed, $render->refresh()->status);
    }

    public function test_a_single_content_can_be_retried_by_id(): void
    {
        $wanted = $this->failed(Stage::Download);
        $other = $this->failed(Stage::Download);

        $this->artisan('converters:retry', ['--content' => [strtoupper($wanted->content_id)]])->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $wanted->refresh()->status);
        $this->assertSame(ConversionStatus::Failed, $other->refresh()->status);
    }

    public function test_it_frees_the_content_in_the_archive_as_well_as_in_the_panel(): void
    {
        // The archive holds the lock, and it is still holding it: a content this pipeline gave up on
        // carries the failure marker, and one the retired pipeline died on carries that worker's GUID.
        // Putting the panel's row back without freeing the archive would spend the content's three
        // attempts on "the archive has this content reserved by another worker" and stop where it began.
        $archive = new FakeArchive;
        $conversion = $this->failed(Stage::Upload);
        $archive->addContent($conversion->content_id)->reserve($conversion->content_id, 'a worker that is long gone');
        $this->app->instance(ArchiveGateway::class, $archive);

        $this->artisan('converters:retry', ['--all' => true])
            ->expectsOutputToContain('freed in the archive')
            ->assertSuccessful();

        $this->assertSame([$conversion->content_id], $archive->freedContents());
        $this->assertNull($archive->ownerOf($conversion->content_id));
        $this->assertSame(ConversionStatus::Pending, $conversion->refresh()->status);
    }

    public function test_a_content_the_archive_counts_as_converted_is_left_alone_there(): void
    {
        $archive = new FakeArchive;
        $conversion = $this->failed(Stage::Upload);
        $archive->addContent($conversion->content_id);
        $archive->markConverted($conversion->content_id);
        $this->app->instance(ArchiveGateway::class, $archive);

        $this->artisan('converters:retry', ['--all' => true])->assertSuccessful();

        // Queued again here, but its verdict in the archive is not this command's to overturn.
        $this->assertSame([], $archive->freedContents());
        $this->assertSame([$conversion->content_id], $archive->convertedContents());
        $this->assertSame(ConversionStatus::Pending, $conversion->refresh()->status);
    }

    public function test_an_unknown_step_is_refused_with_the_list_of_steps(): void
    {
        $this->artisan('converters:retry', ['--stage' => 'uploading'])
            ->expectsOutputToContain('There is no step "uploading"')
            ->assertFailed();
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
            'finished_at' => now()->subMinutes(5),
        ]);
    }

    private function done(): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Done,
            'pages' => 4,
            'finished_at' => now(),
        ]);
    }
}
