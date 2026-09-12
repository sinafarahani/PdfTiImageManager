<?php

namespace Tests\Feature\Livewire;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Livewire\Action;
use App\Models\Conversion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Sleep;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_administrators_may_start_and_stop_the_converters(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->assertTrue(Gate::forUser($admin)->allows('start-action'));
        $this->assertFalse(Gate::forUser($user)->allows('start-action'));
    }

    public function test_dashboard_shows_the_converter_status(): void
    {
        $this->withoutVite();
        $this->actingAs(User::factory()->create());

        $response = $this->get('/dashboard');

        $response->assertSeeLivewire(Action::class);
        $response->assertSee('Stopped');
    }

    public function test_a_user_who_is_not_an_administrator_cannot_start_the_converters(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(Action::class)->call('start')->assertForbidden();

        $this->assertSame(ConverterStatus::STOPPED, app(ConverterStatus::class)->current()['status']);
    }

    public function test_a_user_who_is_not_an_administrator_cannot_stop_the_converters(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        $this->actingAs(User::factory()->create());

        Livewire::test(Action::class)->call('stop')->assertForbidden();

        $this->assertSame(ConverterStatus::RUNNING, app(ConverterStatus::class)->current()['status']);
    }

    #[TestWith([0])]
    #[TestWith([65])]
    #[TestWith(['abc'])]
    #[TestWith([''])]
    public function test_start_rejects_an_invalid_number_of_contents(int|string $threads): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', $threads)
            ->call('start')
            ->assertHasErrors(['threads']);

        $this->assertSame(ConverterStatus::STOPPED, app(ConverterStatus::class)->current()['status']);
    }

    public function test_start_lets_the_dispatcher_hand_out_contents_and_stop_takes_nothing_new(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $component = Livewire::test(Action::class)
            ->set('threads', 6)
            ->call('start')
            ->assertHasNoErrors()
            ->assertSet('status', ConverterStatus::RUNNING)
            ->assertSet('runningThreads', 6);

        $this->assertSame(6, app(ConverterStatus::class)->current()['threads']);

        $component->call('stop')->assertSet('status', ConverterStatus::STOPPED)->assertSee('Stopped');
    }

    public function test_it_shows_started_with_the_number_of_contents_being_converted(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        $this->conversion(ConversionStatus::Claimed);
        $this->conversion(ConversionStatus::Claimed);
        $this->conversion(ConversionStatus::Pending);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->assertSee('Started')
            ->assertSee('2 contents converting')
            ->assertSeeHtml('wire:click="stop"');
    }

    public function test_it_shows_starting_until_a_worker_picks_up_the_first_content(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        $this->conversion(ConversionStatus::Pending);
        $this->actingAs(User::factory()->admin()->create());

        // Stop stays available while starting: the buttons are never blocked by the state.
        Livewire::test(Action::class)
            ->assertSee('Starting')
            ->assertSeeHtml('wire:click="stop"');
    }

    public function test_it_shows_stopping_while_the_last_contents_finish_and_keeps_start_available(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::STOPPED);
        $this->conversion(ConversionStatus::Claimed);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->assertSee('Stopping')
            ->assertSee('1 content finishing')
            ->assertSeeHtml('wire:submit="start"');
    }

    public function test_it_shows_how_much_work_is_waiting_and_what_failed(): void
    {
        $this->conversion(ConversionStatus::Pending);
        $this->conversion(ConversionStatus::Pending);
        $this->conversion(ConversionStatus::Failed);
        $this->conversion(ConversionStatus::Done, finishedAt: now());
        $this->actingAs(User::factory()->create());

        Livewire::test(Action::class)
            ->assertSet('counts.waiting', 2)
            ->assertSet('counts.failed', 1)
            ->assertSet('counts.converted_today', 1)
            ->assertSee('Waiting')
            ->assertSee('Failed');
    }

    public function test_contents_whose_source_file_is_gone_are_counted_apart_from_failures(): void
    {
        // The archive lists thousands of files it no longer has. Those are its own stale rows, not
        // conversions that went wrong, and counting them together would bury the ones worth looking at.
        $this->conversion(ConversionStatus::Failed, stage: Stage::Missing);
        $this->conversion(ConversionStatus::Failed, stage: Stage::Missing);
        $this->conversion(ConversionStatus::Failed, stage: Stage::Upload);
        $this->actingAs(User::factory()->create());

        Livewire::test(Action::class)
            ->assertSet('counts.missing', 2)
            ->assertSet('counts.failed', 1)
            ->assertSee('No source file');
    }

    public function test_polling_keeps_the_number_being_typed(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', 9)
            ->call('syncState')
            ->assertSet('threads', 9);
    }

    public function test_polling_shows_a_start_made_in_another_browser(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $component = Livewire::test(Action::class)->assertSet('status', ConverterStatus::STOPPED);

        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 6);
        $component->call('syncState');

        $component->assertSet('status', ConverterStatus::RUNNING)->assertSet('runningThreads', 6);
    }

    public function test_polling_shows_the_work_finishing_without_a_start_or_stop(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        $conversion = $this->conversion(ConversionStatus::Claimed);
        $this->actingAs(User::factory()->admin()->create());
        $component = Livewire::test(Action::class)->assertSet('counts.converting', 1);

        $conversion->update(['status' => ConversionStatus::Done, 'finished_at' => now()]);
        $component->call('syncState');

        $component->assertSet('counts.converting', 0)->assertSet('counts.converted_today', 1);
    }

    public function test_start_and_stop_report_a_start_or_stop_in_progress(): void
    {
        Sleep::fake(syncWithCarbon: true);
        app(ConverterStatus::class)->lock()->get();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', 2)
            ->call('start')
            ->assertHasErrors(['threads'])
            ->assertSet('status', ConverterStatus::STOPPED);

        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        Livewire::test(Action::class)
            ->call('stop')
            ->assertHasErrors(['threads'])
            ->assertSet('status', ConverterStatus::RUNNING);
    }

    public function test_polling_clears_an_error_once_the_state_changes(): void
    {
        Sleep::fake(syncWithCarbon: true);
        app(ConverterStatus::class)->lock()->get();
        $this->actingAs(User::factory()->admin()->create());
        $component = Livewire::test(Action::class)->call('start')->assertHasErrors(['threads']);

        Cache::forget('converter.lock');
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 3);
        $component->call('syncState');

        $component->assertHasNoErrors()->assertSet('status', ConverterStatus::RUNNING);
    }

    public function test_it_shows_the_state_left_by_the_previous_version_of_the_panel(): void
    {
        Cache::forever('action_started', true);
        Cache::forever('action_threads', '6');
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->assertSet('status', ConverterStatus::RUNNING)
            ->assertSet('runningThreads', 6)
            ->assertSeeHtml('wire:click="stop"');
    }

    private function conversion(ConversionStatus $status, mixed $finishedAt = null, ?Stage $stage = null): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => $status,
            'failure_stage' => $stage,
            'worker' => $status === ConversionStatus::Claimed ? 'test' : null,
            'claimed_at' => $status === ConversionStatus::Claimed ? now() : null,
            'heartbeat_at' => $status === ConversionStatus::Claimed ? now() : null,
            'finished_at' => $finishedAt,
        ]);
    }
}
