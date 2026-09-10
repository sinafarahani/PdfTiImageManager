<?php

namespace Tests\Feature\Livewire;

use App\Actions\Converter\ConverterStatus;
use App\Jobs\RunJob;
use App\Livewire\Action;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ActionTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        // A converter folder with the base worker folder 0, laid out like the one on the server.
        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-manager-'.uniqid();
        $base = $this->root.DIRECTORY_SEPARATOR.'0';
        File::ensureDirectoryExists($base);
        File::put($base.DIRECTORY_SEPARATOR.'e0.exe', 'worker');
        File::put($base.DIRECTORY_SEPARATOR.'0.exe', 'converter');
        File::put($base.DIRECTORY_SEPARATOR.'e0.exe.config', '<add key="SharedFolder" value="d:\\0\\" />');
        File::put($base.DIRECTORY_SEPARATOR.'config.txt', '0');

        config([
            'app.pdfToImg' => $this->root,
            'app.runnerPath' => 'C:\\runner\\Forms_Runner.exe',
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

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
        Bus::fake();
        $this->actingAs(User::factory()->create());

        Livewire::test(Action::class)->call('start')->assertForbidden();

        Bus::assertNothingDispatched();
        $this->assertSame(ConverterStatus::STOPPED, app(ConverterStatus::class)->current()['status']);
    }

    #[TestWith([0])]
    #[TestWith([65])]
    #[TestWith(['abc'])]
    #[TestWith([''])]
    public function test_start_rejects_an_invalid_number_of_processes(int|string $threads): void
    {
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', $threads)
            ->call('start')
            ->assertHasErrors(['threads']);

        Bus::assertNothingDispatched();
    }

    public function test_start_creates_missing_worker_folders_from_folder_0_and_starts_one_worker_per_folder(): void
    {
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', 3)
            ->call('start')
            ->assertHasNoErrors()
            ->assertSet('status', ConverterStatus::RUNNING)
            ->assertSet('runningThreads', 3)
            ->assertSee('3 processes');

        $worker = $this->root.DIRECTORY_SEPARATOR.'2'.DIRECTORY_SEPARATOR;
        $this->assertFileExists($worker.'e2.exe');
        $this->assertFileExists($worker.'2.exe');
        $this->assertFileDoesNotExist($worker.'e0.exe');
        $this->assertStringEqualsFile($worker.'config.txt', '2');
        $this->assertStringEqualsFile($worker.'e2.exe.config', '<add key="SharedFolder" value="d:\\2\\" />');
        $this->assertSame(
            [$this->folder(0), $this->folder(1), $this->folder(2)],
            $this->dispatchedWorkerFolders(),
        );
    }

    public function test_start_picks_the_worker_folders_by_number(): void
    {
        foreach (range(1, 11) as $index) {
            File::ensureDirectoryExists($this->folder($index));
        }
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)->set('threads', 3)->call('start');

        $this->assertSame([$this->folder(0), $this->folder(1), $this->folder(2)], $this->dispatchedWorkerFolders());
    }

    public function test_start_reports_a_missing_base_worker_folder(): void
    {
        File::deleteDirectory($this->folder(0));
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->set('threads', 2)
            ->call('start')
            ->assertHasErrors(['threads'])
            ->assertSet('status', ConverterStatus::STOPPED);

        Bus::assertNothingDispatched();
    }

    public function test_start_does_nothing_while_the_converters_are_running(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)->call('start')->assertHasErrors(['threads']);

        Bus::assertNothingDispatched();
    }

    public function test_stop_asks_every_worker_to_finish_and_shows_stopped_at_once(): void
    {
        File::ensureDirectoryExists($this->folder(1));
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->call('stop')
            ->assertSet('status', ConverterStatus::STOPPED)
            ->assertSee('Stopped');

        $this->assertStringEqualsFile($this->folder(0).DIRECTORY_SEPARATOR.'status.txt', 'terminate');
        $this->assertStringEqualsFile($this->folder(1).DIRECTORY_SEPARATOR.'status.txt', 'terminate');
        Bus::assertNothingDispatched();
    }

    public function test_stopping_again_keeps_the_time_of_the_pending_stop(): void
    {
        $statusFile = $this->folder(0).DIRECTORY_SEPARATOR.'status.txt';
        File::put($statusFile, 'terminate');
        touch($statusFile, $askedAt = now()->subMinutes(5)->getTimestamp());
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)->call('stop');

        clearstatcache();
        $this->assertSame($askedAt, File::lastModified($statusFile));
    }

    public function test_start_right_after_stop_queues_the_workers_of_the_new_run(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 2);
        Bus::fake();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(Action::class)
            ->call('stop')
            ->set('threads', 2)
            ->call('start')
            ->assertHasNoErrors()
            ->assertSet('status', ConverterStatus::RUNNING);

        $currentVersion = app(ConverterStatus::class)->current()['version'];
        $startVersions = Bus::dispatched(RunJob::class)
            ->map(fn (RunJob $job): ?int => (fn (): ?int => $this->startVersion)->call($job))
            ->all();
        $this->assertSame([$currentVersion, $currentVersion], $startVersions);
    }

    public function test_polling_keeps_the_number_of_processes_being_typed(): void
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

        $component->assertSet('status', ConverterStatus::RUNNING);
        $component->assertSet('runningThreads', 6);
        $component->assertSee('6 processes');
    }

    private function folder(int $index): string
    {
        return $this->root.DIRECTORY_SEPARATOR.$index;
    }

    /**
     * @return list<string>
     */
    private function dispatchedWorkerFolders(): array
    {
        return Bus::dispatched(RunJob::class)
            ->map(fn (RunJob $job): string => (fn (): string => $this->dirPath)->call($job))
            ->sort()
            ->values()
            ->all();
    }
}
