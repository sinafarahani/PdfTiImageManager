<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Discovery is the part of the old pipeline that was actually broken: its queue was built once from a
 * snapshot and never refreshed, so contents added afterwards were never converted. These tests are
 * about the two things that stops: the queue is filled again on every run, and the watermark it scans
 * from moves forward without ever losing a content in the gap.
 */
class DiscoverContentsTest extends TestCase
{
    use RefreshDatabase;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = new FakeArchive;
        $this->app->instance(ArchiveGateway::class, $this->archive);

        config([
            'converter.discovery.batch' => 100,
            'converter.discovery.overlap_minutes' => 60,
            'converter.discovery.seed_from_pdfconvert' => true,
        ]);
    }

    public function test_the_first_run_seeds_from_the_old_queue_and_no_later_run_does(): void
    {
        $this->archive
            ->addLegacyContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'))
            ->addLegacyContent('C2', 1, CarbonImmutable::parse('2025-02-01 10:00:00'));

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(['C1', 'C2'], Conversion::query()->orderBy('id')->pluck('content_id')->all());

        // The seed must not move the ProcessDate watermark: PdfConvert.ProcessDate is the old tooling's
        // own column and says nothing about what the archive still has to offer below it.
        $this->assertNull($this->watermark());
        $this->assertTrue($this->seeded());

        // Both a content the old queue gained afterwards and one only the archive knows about: the
        // second run must take the archive route, not seed all over again.
        $this->archive->addLegacyContent('C3', 1, CarbonImmutable::parse('2025-02-02 10:00:00'));
        $this->archive->addContent('C4', 1, CarbonImmutable::parse('2025-03-01 09:00:00'));

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(['C1', 'C2', 'C4'], Conversion::query()->orderBy('id')->pluck('content_id')->all());
    }

    public function test_seeding_pages_through_a_legacy_queue_longer_than_one_batch(): void
    {
        config(['converter.discovery.batch' => 2]);

        foreach (range(1, 5) as $index) {
            $this->archive->addLegacyContent('C'.$index, 1, CarbonImmutable::parse('2025-01-0'.$index.' 10:00:00'));
        }

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(5, Conversion::query()->count());
        $this->assertNull($this->watermark());
    }

    public function test_seeding_does_not_hide_the_contents_the_old_queue_never_held(): void
    {
        // The old pipeline claimed this one and never finished it, so it is not in PdfConvert with
        // state 0 - but it is still waiting in the archive, and its ProcessDate is older than the
        // newest row the seed reads. A watermark taken from the seed would step over it for good.
        $this->archive
            ->addContent('C0', 1, CarbonImmutable::parse('2024-03-01 08:00:00'))
            ->addLegacyContent('C1', 1, CarbonImmutable::parse('2025-02-01 10:00:00'));

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(['C1'], Conversion::query()->pluck('content_id')->all());

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(['C1', 'C0'], Conversion::query()->orderBy('id')->pluck('content_id')->all());
        $this->assertSame('2024-03-01 08:00:00', $this->watermark());
    }

    public function test_a_pass_advances_the_watermark_to_the_newest_process_date_it_saw(): void
    {
        config(['converter.discovery.seed_from_pdfconvert' => false]);

        $this->archive
            ->addContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'))
            ->addContent('C2', 1, CarbonImmutable::parse('2025-01-07 17:10:00'));

        $this->artisan('converters:discover')->assertSuccessful();

        $this->assertSame(2, Conversion::query()->count());
        $this->assertSame('2025-01-07 17:10:00', $this->watermark());
    }

    public function test_the_overlap_re_scan_finds_new_contents_without_duplicating_the_old_ones(): void
    {
        config(['converter.discovery.seed_from_pdfconvert' => false]);

        $this->archive->addContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'));

        $this->artisan('converters:discover')->assertSuccessful();

        // Inside the hour the previous pass already reported, which is exactly the content a
        // watermark without an overlap would step over.
        $this->archive->addContent('C2', 1, CarbonImmutable::parse('2025-01-07 16:50:00'));

        $this->artisan('converters:discover')
            ->expectsOutputToContain('Queued 1 new content(s)')
            ->assertSuccessful();

        $this->assertSame(['C1', 'C2'], Conversion::query()->orderBy('id')->pluck('content_id')->all());
        $this->assertSame(1, Conversion::query()->where('content_id', 'C1')->count());

        // The re-scan reported C1 again, whose ProcessDate is older than the point already reached:
        // the watermark must not be dragged backwards by it.
        $this->assertSame('2025-01-07 16:50:00', $this->watermark());
    }

    public function test_a_pass_that_finds_nothing_keeps_the_watermark_and_the_queue(): void
    {
        config(['converter.discovery.seed_from_pdfconvert' => false]);

        $this->archive->addContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'));

        $this->artisan('converters:discover')->assertSuccessful();
        $this->artisan('converters:discover')
            ->expectsOutputToContain('Queued 0 new content(s)')
            ->assertSuccessful();

        $this->assertSame(1, Conversion::query()->count());
        $this->assertSame('2025-01-07 16:39:53', $this->watermark());
    }

    private function watermark(): ?string
    {
        /** @var string|null $processedUntil */
        $processedUntil = DB::table('conversion_watermarks')->where('name', 'discovery')->value('processed_until');

        return $processedUntil === null ? null : CarbonImmutable::parse($processedUntil)->toDateTimeString();
    }

    private function seeded(): bool
    {
        return DB::table('conversion_watermarks')->where('name', 'seeded-from-pdfconvert')->exists();
    }
}
