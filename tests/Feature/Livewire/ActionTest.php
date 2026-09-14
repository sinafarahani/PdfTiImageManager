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

    public function test_the_dashboard_is_the_front_page_now(): void
    {
        // There is no separate dashboard any more - it showed exactly what the front page shows - but
        // the route stays, because it is where Fortify sends somebody after they sign in.
        $this->withoutVite();
        $this->actingAs(User::factory()->create());

        $this->get('/dashboard')->assertRedirect('/');

        $response = $this->get('/');

        $response->assertSeeLivewire(Action::class);
        $response->assertSee('Stopped');
    }

    public function test_a_signed_in_user_gets_the_account_menu_instead_of_a_sign_in_link(): void
    {
        $this->withoutVite();
        $user = User::factory()->create(['name' => 'Sina']);
        $this->actingAs($user);

        $response = $this->get('/');

        $response->assertSee('Sina');
        $response->assertSee('Manage Account');
        $response->assertSee('Log Out');
        $response->assertDontSee('Sign in');
    }

    public function test_anybody_can_watch_the_status_page_without_signing_in(): void
    {
        $this->withoutVite();
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        $this->conversion(ConversionStatus::Pending);

        $response = $this->get('/');

        $response->assertOk();
        $response->assertSeeLivewire(Action::class);
        $response->assertSee('Waiting');
        $response->assertSee('Sign in');

        // Watching is all a visitor gets: no buttons, and the component refuses the actions anyway.
        $response->assertDontSee('wire:click="stop"', escape: false);
        $response->assertSee('Only administrators can start or stop the converters.');
    }

    public function test_a_visitor_who_is_not_signed_in_cannot_start_or_stop(): void
    {
        // The buttons are hidden from a visitor, so this is about the endpoint behind them: the gate is
        // what refuses, not the template.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);

        Livewire::test(Action::class)->call('stop')->assertForbidden();
        Livewire::test(Action::class)->set('threads', 4)->call('start')->assertForbidden();

        $this->assertSame(ConverterStatus::RUNNING, app(ConverterStatus::class)->current()['status']);
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

    public function test_the_buttons_do_not_flicker_while_the_page_polls(): void
    {
        // wire:loading with no target matches every request the component makes, and the root polls
        // syncState every two seconds - so both buttons spent the day disabling and re-enabling
        // themselves. wire:target is what scopes the loading state to the action that was clicked.
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->assertSeeHtml('wire:submit="start"')
            ->assertSeeHtml('wire:target="start"');

        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);

        Livewire::test(Action::class)
            ->assertSeeHtml('wire:click="stop"')
            ->assertSeeHtml('wire:target="stop"');
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
