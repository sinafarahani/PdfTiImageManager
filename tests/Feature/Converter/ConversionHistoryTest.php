<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Pipeline\ConversionHistory;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\HistoryBucket;
use App\Actions\Converter\Pipeline\HistoryRange;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConversionHistoryTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        $this->now = CarbonImmutable::parse('2026-09-13 14:30:00');
        $this->travelTo($this->now);
    }

    public function test_the_last_day_is_counted_hour_by_hour(): void
    {
        $this->converted($this->now->subHours(2));
        $this->converted($this->now->subHours(2)->addMinutes(20));
        $this->converted($this->now);

        $chart = app(ConversionHistory::class)->of(HistoryRange::Day);

        $this->assertSame(HistoryBucket::Hour, $chart['bucket']);
        $this->assertCount(24, $chart['points']);
        $this->assertSame(3, $chart['total']);
        $this->assertSame(2, $chart['peak']);

        $this->assertSame(2, $this->pointFor($chart, '2026-09-13 12:00')['total']);
        $this->assertSame(1, $this->pointFor($chart, '2026-09-13 14:00')['total']);
    }

    public function test_an_hour_in_which_nothing_was_converted_is_an_empty_column_not_a_missing_one(): void
    {
        // A chart that closes its gaps says the pipeline never stopped, which is the one thing a
        // person reading it is trying to find out.
        $this->converted($this->now->subHours(5));

        $chart = app(ConversionHistory::class)->of(HistoryRange::Day);

        $quiet = $this->pointFor($chart, '2026-09-13 10:00');
        $this->assertSame(0, $quiet['total']);
        $this->assertSame(0.0, $quiet['share']);
        $this->assertCount(24, $chart['points']);
    }

    public function test_a_week_and_a_month_are_counted_day_by_day(): void
    {
        $this->converted($this->now->subDays(3)->setTime(9, 0));
        $this->converted($this->now->subDays(3)->setTime(17, 0));
        $this->converted($this->now->subDays(20));

        $week = app(ConversionHistory::class)->of(HistoryRange::Week);

        $this->assertSame(HistoryBucket::Day, $week['bucket']);
        $this->assertCount(7, $week['points']);
        $this->assertSame(2, $week['total']);
        $this->assertSame(2, $this->pointFor($week, '2026-09-10')['total']);

        $month = app(ConversionHistory::class)->of(HistoryRange::Month);

        $this->assertCount(30, $month['points']);
        $this->assertSame(3, $month['total']);
    }

    public function test_all_is_read_by_the_day_while_the_panel_is_young(): void
    {
        $this->converted($this->now->subDays(9));
        $this->converted($this->now);

        $chart = app(ConversionHistory::class)->of(HistoryRange::All);

        $this->assertSame(HistoryBucket::Day, $chart['bucket']);
        $this->assertCount(10, $chart['points']);
        $this->assertSame(2, $chart['total']);
    }

    public function test_all_is_read_by_the_month_once_there_is_too_much_of_it(): void
    {
        // Day by day, two years is 700 columns in a chart 600 pixels wide.
        $this->converted($this->now->subYears(2));
        $this->converted($this->now);

        $chart = app(ConversionHistory::class)->of(HistoryRange::All);

        $this->assertSame(HistoryBucket::Month, $chart['bucket']);
        $this->assertCount(25, $chart['points']);
        $this->assertSame(1, $this->pointFor($chart, '2024-09')['total']);
        $this->assertSame(1, $this->pointFor($chart, '2026-09')['total']);
    }

    public function test_only_converted_contents_are_counted(): void
    {
        $this->converted($this->now);
        $this->finished(ConversionStatus::Failed, $this->now, Stage::Render);
        $this->finished(ConversionStatus::Cancelled, $this->now, Stage::Skipped);

        $chart = app(ConversionHistory::class)->of(HistoryRange::Day);

        $this->assertSame(1, $chart['total']);
    }

    public function test_it_names_the_busiest_column(): void
    {
        $this->converted($this->now->subDays(2));
        $this->converted($this->now->subDays(2));
        $this->converted($this->now->subDays(2));
        $this->converted($this->now);

        $chart = app(ConversionHistory::class)->of(HistoryRange::Week);

        $this->assertSame(3, $chart['busiest']['total']);
        $this->assertStringContainsString('11 September 2026', (string) $chart['busiest']['title']);
    }

    public function test_the_database_groups_by_the_same_field_spelling_php_builds_the_keys_from(): void
    {
        // The columns are filled in by PHP and counted by the database, and the two are joined by this
        // string. MySQL's date_format and SQLite's strftime spell %Y, %m, %d and %H identically, which
        // is the only reason one pattern can serve both - if that ever stops being true the chart goes
        // uniformly empty rather than subtly wrong, but pin the statement anyway.
        $this->converted($this->now);
        DB::enableQueryLog();

        app(ConversionHistory::class)->of(HistoryRange::Day);

        $grouped = collect(DB::getQueryLog())->pluck('query')->first(
            fn (string $query): bool => str_contains($query, 'as bucket'),
        );

        $this->assertNotNull($grouped);
        $this->assertStringContainsString("strftime('%Y-%m-%d %H:00', finished_at)", (string) $grouped);
    }

    public function test_nothing_converted_yet_draws_no_columns_at_all(): void
    {
        $chart = app(ConversionHistory::class)->of(HistoryRange::All);

        $this->assertSame([], $chart['points']);
        $this->assertSame(0, $chart['total']);
        $this->assertNull($chart['busiest']);
    }

    /**
     * @param  array{points: list<array{key: string, total: int, share: float}>}  $chart
     * @return array{key: string, total: int, share: float}
     */
    private function pointFor(array $chart, string $key): array
    {
        foreach ($chart['points'] as $point) {
            if ($point['key'] === $key) {
                return $point;
            }
        }

        $this->fail("The chart has no column for {$key}.");
    }

    private function converted(CarbonImmutable $at): Conversion
    {
        return $this->finished(ConversionStatus::Done, $at);
    }

    private function finished(ConversionStatus $status, CarbonImmutable $at, ?Stage $stage = null): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => $status,
            'failure_stage' => $stage,
            'pages' => $status === ConversionStatus::Done ? 3 : null,
            'finished_at' => $at,
        ]);
    }
}
