<?php

namespace Tests\Feature\Livewire;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Livewire\ConversionChart;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ConversionChartTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-09-13 14:30:00'));
    }

    public function test_the_chart_is_on_the_front_page_for_anyone_who_can_reach_it(): void
    {
        $this->withoutVite();
        $this->converted(now());

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeLivewire(ConversionChart::class);
        $response->assertSee('Converted over time');
    }

    public function test_it_shows_the_last_seven_days_to_begin_with(): void
    {
        $this->converted(now());
        $this->converted(now()->subDays(2));

        Livewire::test(ConversionChart::class)
            ->assertSet('range', 'week')
            ->assertSee('Contents converted per day, over the last 7 days.')
            ->assertSee('2 contents converted in this period');
    }

    public function test_the_range_can_be_changed(): void
    {
        $this->converted(now());
        $this->converted(now()->subDays(20));

        $component = Livewire::test(ConversionChart::class);

        // The week leaves the older one out; the month takes it in.
        $component->assertSee('1 content converted in this period');
        $component->call('show', 'month')->assertSee('2 contents converted in this period');
        $component->call('show', 'day')->assertSee('Contents converted per hour, over the last 24 hours.');
        $component->call('show', 'all')->assertSee('Contents converted since the panel took over.');
    }

    public function test_a_range_that_does_not_exist_is_ignored(): void
    {
        $this->converted(now());

        Livewire::test(ConversionChart::class)
            ->call('show', 'fortnight')
            ->assertSet('range', 'week')
            ->assertSee('1 content converted in this period');
    }

    public function test_a_column_says_what_it_is_when_it_is_pointed_at(): void
    {
        // The columns are too narrow to label individually, so the title is the only way to read one.
        $this->converted(now()->subDays(2));
        $this->converted(now()->subDays(2));

        Livewire::test(ConversionChart::class)
            ->assertSee('Friday 11 September 2026 &mdash; 2 contents converted', escape: false);
    }

    public function test_it_says_so_when_nothing_has_been_converted(): void
    {
        Livewire::test(ConversionChart::class)
            ->call('show', 'all')
            ->assertSee('Nothing has been converted yet.');
    }

    private function converted(mixed $at): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Done,
            'pages' => 4,
            'finished_at' => $at,
        ]);
    }
}
