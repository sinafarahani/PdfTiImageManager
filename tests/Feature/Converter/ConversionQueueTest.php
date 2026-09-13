<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

/**
 * The queue's promises: a content is queued once however often discovery reports it, a claimed
 * content belongs to exactly one worker, and a content that keeps failing eventually stops being
 * retried. The old pipeline kept none of these - it claimed through a stored procedure that could
 * hand the same content to two workers and had no idea how often a content had already been tried.
 */
class ConversionQueueTest extends TestCase
{
    use RefreshDatabase;

    private ConversionQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->queue = new ConversionQueue;
    }

    public function test_adding_ignores_contents_that_are_already_in_the_queue(): void
    {
        $this->assertSame(2, $this->queue->add([$this->content('C1'), $this->content('C2')]));
        $this->assertSame(0, $this->queue->add([$this->content('C1'), $this->content('C2')]));
        $this->assertSame(1, $this->queue->add([$this->content('C2'), $this->content('C3')]));

        $this->assertSame(['C1', 'C2', 'C3'], Conversion::query()->orderBy('id')->pluck('content_id')->all());
        $this->assertSame(ConversionStatus::Pending, Conversion::query()->first()?->status);
    }

    public function test_adding_does_not_disturb_a_content_that_is_already_finished(): void
    {
        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();
        $this->queue->succeed($conversion, 12);

        $this->assertSame(0, $this->queue->add([$this->content('C1')]));

        $conversion->refresh();

        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(12, $conversion->pages);
    }

    public function test_adding_a_large_batch_is_chunked_and_de_duplicated(): void
    {
        $contents = [];

        for ($index = 1; $index <= 1200; $index++) {
            $contents[] = $this->content('C'.$index);
        }

        // Twice the same 1200 contents: the repeats span chunk boundaries, which is where a
        // de-duplication done per chunk in PHP rather than by the unique index would fall over.
        $this->assertSame(1200, $this->queue->add([...$contents, ...$contents]));
        $this->assertSame(1200, Conversion::query()->count());
    }

    public function test_claiming_never_hands_one_content_to_two_workers(): void
    {
        $this->queue->add([$this->content('C1'), $this->content('C2'), $this->content('C3'), $this->content('C4')]);

        $first = $this->queue->claim('worker-1', 2);
        $second = $this->queue->claim('worker-2', 2);

        $this->assertSame(['C1', 'C2'], $first->pluck('content_id')->all());
        $this->assertSame(['C3', 'C4'], $second->pluck('content_id')->all());
        $this->assertSame([], array_intersect($first->pluck('id')->all(), $second->pluck('id')->all()));
        $this->assertCount(0, $this->queue->claim('worker-3', 2));

        $claimed = Conversion::query()->find($first->first()?->id);

        $this->assertSame(ConversionStatus::Claimed, $claimed?->status);
        $this->assertSame('worker-1', $claimed?->worker);
        $this->assertNotNull($claimed?->heartbeat_at);
        $this->assertSame(0, $claimed?->attempts);
    }

    public function test_a_retryable_failure_goes_back_to_pending_until_the_attempts_run_out(): void
    {
        config(['converter.failure.max_attempts' => 3]);

        $this->queue->add([$this->content('C1')]);

        foreach ([1, 2] as $attempt) {
            $conversion = $this->queue->claim('worker-1', 1)->sole();

            $this->queue->fail($conversion, Stage::Render, 'pdf2img exited with 1', retryable: true);

            $this->assertSame(ConversionStatus::Pending, $conversion->status);
            $this->assertSame($attempt, $conversion->attempts);
            $this->assertNull($conversion->worker);
            $this->assertNull($conversion->finished_at);
            $this->assertSame(Stage::Render, $conversion->failure_stage);
        }

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $this->queue->fail($conversion, Stage::Render, 'pdf2img exited with 1', retryable: true);

        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(3, $conversion->attempts);
        $this->assertNotNull($conversion->finished_at);
        $this->assertCount(0, $this->queue->claim('worker-1', 1));
    }

    public function test_the_attempt_is_counted_from_the_row_and_not_from_the_model_in_hand(): void
    {
        config(['converter.failure.max_attempts' => 3]);

        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        // The reconciler counted an attempt while this worker was still running. Adding one to the
        // model's own copy would write that increment away and leave the content retried forever.
        Conversion::query()->whereKey($conversion->id)->update(['attempts' => 1]);

        $this->assertTrue($this->queue->fail($conversion, Stage::Render, 'pdf2img exited with 1', retryable: true));
        $this->assertSame(2, $conversion->attempts);
        $this->assertSame(2, Conversion::query()->sole()->attempts);
    }

    public function test_a_heartbeat_written_twice_in_the_same_second_still_says_the_worker_holds_it(): void
    {
        // The heartbeat is what tells a slow conversion from a dead worker, and a false answer makes
        // a worker give up a content it is holding. Writing the timestamp the row already holds must
        // therefore still answer yes: on MySQL an UPDATE that changes nothing reports no rows, and
        // reading that count as the answer cost every conversion on the server its first heartbeat.
        CarbonImmutable::setTestNow(now());
        $queue = new ConversionQueue;
        $queue->add([new DiscoveredContent(fake()->uuid(), 65, null)]);
        $conversion = $queue->claim('worker-1', 1)->first();
        $this->assertNotNull($conversion);

        $this->assertTrue($queue->heartbeat($conversion));
        $this->assertTrue($queue->heartbeat($conversion));

        CarbonImmutable::setTestNow();
    }

    public function test_a_worker_that_lost_its_content_can_no_longer_change_it(): void
    {
        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        // What the reconciler leaves behind once it has decided this worker is gone: the attempt's
        // page rows are deleted and the content waits for somebody else. Nothing the old worker does
        // afterwards may touch it - that is how one content ends up converted twice.
        Conversion::query()->whereKey($conversion->id)->update([
            'status' => ConversionStatus::Pending->value,
            'worker' => null,
            'heartbeat_at' => null,
            'claimed_at' => null,
        ]);

        $this->assertFalse($this->queue->heartbeat($conversion));
        $this->assertFalse($this->queue->succeed($conversion, 12));
        $this->assertFalse($this->queue->fail($conversion, Stage::Render, 'too late', retryable: false));

        $row = Conversion::query()->sole();

        $this->assertSame(ConversionStatus::Pending, $row->status);
        $this->assertSame(0, $row->attempts);
        $this->assertNull($row->heartbeat_at);
        $this->assertNull($row->pages);
    }

    public function test_a_failure_that_cannot_be_retried_stays_failed_on_the_first_attempt(): void
    {
        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $this->queue->fail($conversion, Stage::Download, 'the PDF is not on the FTP site', retryable: false);

        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(1, $conversion->attempts);
        $this->assertSame('the PDF is not on the FTP site', $conversion->failure_reason);
        $this->assertSame(1, Conversion::query()->failed()->count());
    }

    public function test_stale_finds_the_claims_whose_worker_stopped_reporting(): void
    {
        config(['converter.failure.stale_after_minutes' => 60]);

        $this->queue->add([$this->content('C1'), $this->content('C2'), $this->content('C3')]);

        [$frozen, $working, $neverBeat] = $this->queue->claim('worker-1', 3)->all();

        $frozen->forceFill(['heartbeat_at' => now()->subMinutes(90)])->save();
        $neverBeat->forceFill(['heartbeat_at' => null, 'claimed_at' => now()->subMinutes(90)])->save();

        $this->assertSame(['C1', 'C3'], $this->queue->stale()->pluck('content_id')->all());

        // A pending content is nobody's, however long it has been waiting.
        $this->queue->fail($working, Stage::Render, 'timed out', retryable: true);

        $this->assertSame(['C1', 'C3'], $this->queue->stale()->pluck('content_id')->all());
    }

    public function test_the_page_ledger_records_every_row_and_upload(): void
    {
        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $page = $this->queue->recordPage($conversion, 1, 'MVD-1');

        $this->assertNull($page->remote_path);
        $this->assertFalse($page->uploaded);

        $this->queue->markPageUploaded($page, '/DOI/2025/01/07/16/39/53/MVD-1.jpg', 91_204);

        // Writing page 1 again must not leave two ledger rows behind: the unique key is (conversion, seq).
        $this->queue->recordPage($conversion, 1, 'MVD-1');
        $this->queue->recordPage($conversion, 2, 'MVD-2', '/DOI/2025/01/07/16/39/53/MVD-2.jpg', 80_000);

        $pages = $conversion->pages()->orderBy('seq')->get();

        $this->assertSame(['MVD-1', 'MVD-2'], $pages->pluck('mvd_id')->all());
        $this->assertSame('/DOI/2025/01/07/16/39/53/MVD-1.jpg', $pages->first()?->remote_path);
        $this->assertSame(91_204, $pages->first()?->bytes);
        $this->assertTrue($pages->first()?->uploaded);
        $this->assertFalse($pages->last()?->uploaded);
    }

    public function test_recording_a_page_refuses_to_forget_the_row_a_previous_attempt_wrote(): void
    {
        $this->queue->add([$this->content('C1')]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $this->queue->recordPage($conversion, 1, 'MVD-1');

        // A retry that did not go through the reconciler first. Letting the new id overwrite the old
        // one would leave MVD-1 standing in the archive with nothing pointing at it, and the content
        // would show that page twice in the viewer.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already recorded as MVDContent MVD-1/');

        $this->queue->recordPage($conversion, 1, 'MVD-9');
    }

    public function test_a_content_may_have_more_than_sixty_five_thousand_pages(): void
    {
        // Both counters were smallints. One content in the archive rendered and uploaded 65,535 pages
        // over eleven hours and then threw "Out of range value for column 'seq'" on the next one, twice.
        // The two are asserted together on purpose: seq alone would move the overflow to succeed(),
        // which runs after the archive has been marked converted, and the rollback would then delete
        // every page of a content the archive believes is finished.
        $this->queue->add([$this->content('C1')]);
        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $page = $this->queue->recordPage($conversion, 70_000, 'MVD-70000');
        $this->assertTrue($this->queue->succeed($conversion, 70_000));

        $this->assertSame(70_000, $page->refresh()->seq);
        $this->assertSame(70_000, $conversion->refresh()->pages);

        // SQLite does not enforce integer widths, so the round trip above would pass on a smallint
        // column too. The declared type is what the production MySQL actually holds.
        foreach ([['conversions', 'pages'], ['conversion_pages', 'seq']] as [$table, $column]) {
            $declared = collect(Schema::getColumns($table))->firstWhere('name', $column);

            $this->assertNotNull($declared);
            $this->assertStringNotContainsString('smallint', strtolower((string) $declared['type']), "{$table}.{$column} is still a smallint");
        }
    }

    public function test_missing_names_the_contents_that_did_not_reach_the_queue(): void
    {
        $this->queue->add([$this->content('C1')]);

        $this->assertSame([], $this->queue->missing([$this->content('C1')]));
        $this->assertSame(['C2'], $this->queue->missing([$this->content('C1'), $this->content('C2')]));
    }

    private function content(string $contentId, ?CarbonImmutable $processDate = null): DiscoveredContent
    {
        return new DiscoveredContent($contentId, 1, $processDate);
    }
}
