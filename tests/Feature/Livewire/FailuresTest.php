<?php

namespace Tests\Feature\Livewire;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Livewire\Failures;
use App\Models\Conversion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FailuresTest extends TestCase
{
    use RefreshDatabase;

    public function test_anybody_can_read_the_failure_list_without_signing_in(): void
    {
        $this->withoutVite();
        $this->failure(Stage::Render, 'pdf2img exited with 2 [source: 2023/01/05/16/39/53/a.pdf]');

        $response = $this->get('/failures');

        $response->assertOk();
        $response->assertSeeLivewire(Failures::class);
        $response->assertSee('2023/01/05/16/39/53/a.pdf');
    }

    public function test_it_shows_the_failures_worth_reading_and_not_the_hundreds_of_thousands_that_are_not(): void
    {
        // The whole reason the kinds are separate: a list with the stale rows in it never shows the
        // handful of real failures.
        $this->failure(Stage::Render, 'a broken pdf');
        $this->failure(Stage::Missing, 'the source PDF is not on the file store: 2023/01/05/a.pdf');
        $this->cancelled('the profile is not converted');

        Livewire::test(Failures::class)
            ->assertSee('a broken pdf')
            ->assertDontSee('not on the file store')
            ->assertDontSee('the profile is not converted');
    }

    public function test_each_kind_can_be_asked_for_on_its_own(): void
    {
        $this->failure(Stage::Render, 'a broken pdf');
        $this->failure(Stage::Missing, 'the source PDF is not on the file store: 2023/01/05/a.pdf');
        $this->cancelled('the profile is not converted');

        $component = Livewire::test(Failures::class);

        $component->call('show', 'missing')
            ->assertSee('not on the file store')
            ->assertDontSee('a broken pdf');

        $component->call('show', 'skipped')
            ->assertSee('the profile is not converted')
            ->assertDontSee('a broken pdf');

        $component->call('show', 'all')
            ->assertSee('a broken pdf')
            ->assertSee('not on the file store')
            ->assertSee('the profile is not converted');
    }

    public function test_a_kind_that_does_not_exist_is_ignored(): void
    {
        $this->failure(Stage::Render, 'a broken pdf');

        Livewire::test(Failures::class)
            ->call('show', 'whatever')
            ->assertSet('kind', 'failures')
            ->assertSee('a broken pdf');
    }

    public function test_a_content_can_be_looked_up_by_its_id_or_by_its_path(): void
    {
        // Looking a document up by the path in the reason is what the list is for: the pipeline
        // records the file it was working on so it can be opened straight away.
        $wanted = $this->failure(Stage::Render, 'pdf2img exited with 2 [source: 2023/01/05/16/39/53/a.pdf]');
        $this->failure(Stage::Upload, 'the site refused the image [source: 2024/07/07/08/08/08/b.pdf]');

        Livewire::test(Failures::class)
            ->set('search', '2023/01/05')
            ->assertSee($wanted->content_id)
            ->assertDontSee('the site refused the image');

        Livewire::test(Failures::class)
            ->set('search', $wanted->content_id)
            ->assertSee('pdf2img exited with 2')
            ->assertDontSee('the site refused the image');
    }

    public function test_a_search_that_matches_nothing_says_so(): void
    {
        $this->failure(Stage::Render, 'a broken pdf');

        Livewire::test(Failures::class)
            ->set('search', 'nothing like this')
            ->assertSee('Nothing matches that search.');
    }

    public function test_the_list_is_paginated_newest_first(): void
    {
        foreach (range(1, Failures::PER_PAGE + 5) as $minutes) {
            $this->failure(Stage::Render, "failure number {$minutes}", now()->subMinutes($minutes));
        }

        $component = Livewire::test(Failures::class);

        $component->assertSee('failure number 1')->assertDontSee('failure number 30');
        $component->call('gotoPage', 2)->assertSee('failure number 30');
    }

    public function test_the_counts_on_the_tabs_are_of_the_whole_table_not_the_page(): void
    {
        $this->failure(Stage::Render, 'a broken pdf');
        $this->failure(Stage::Missing, 'gone');
        $this->failure(Stage::Missing, 'gone as well');
        $this->cancelled('not converted');

        Livewire::test(Failures::class)
            ->assertSeeInOrder(['Failed', '1', 'No source file', '2', 'Not converted', '1', 'Everything', '4']);
    }

    public function test_signing_in_changes_nothing_about_what_the_list_shows(): void
    {
        $this->failure(Stage::Render, 'a broken pdf');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Failures::class)->assertSee('a broken pdf');
    }

    private function failure(Stage $stage, string $reason, mixed $finishedAt = null): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Failed,
            'failure_stage' => $stage,
            'failure_reason' => $reason,
            'attempts' => 3,
            'finished_at' => $finishedAt ?? now(),
        ]);
    }

    private function cancelled(string $reason): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Cancelled,
            'failure_stage' => Stage::Skipped,
            'failure_reason' => $reason,
            'finished_at' => now(),
        ]);
    }
}
