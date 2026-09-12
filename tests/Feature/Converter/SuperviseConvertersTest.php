<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * The supervisor is the only process Windows starts, so everything else exists at its pleasure: it
 * decides how many workers run, and it is the thing a person is most likely to start twice.
 */
class SuperviseConvertersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Process::fake();
        Sleep::fake();
        config(['converter.queue.connection' => 'database', 'converter.queue.name' => 'conversions']);
    }

    public function test_it_starts_a_dispatcher_and_one_worker_per_content(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 3);

        $this->artisan('converters:supervise', ['--passes' => 1])->assertSuccessful();

        Process::assertRanTimes($this->ran('converters:dispatch'), 1);
        Process::assertRanTimes($this->ran('queue:work'), 3);
    }

    public function test_a_stop_starts_no_worker_at_all(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::STOPPED);

        $this->artisan('converters:supervise', ['--passes' => 1])->assertSuccessful();

        // The dispatcher keeps running through a Stop - it is what notices a Start - but no worker is
        // started, which is the whole of the safe stop on a system where a worker cannot be signalled.
        Process::assertRanTimes($this->ran('converters:dispatch'), 1);
        Process::assertRanTimes($this->ran('queue:work'), 0);
    }

    public function test_a_stop_keeps_working_until_the_contents_already_handed_out_are_done(): void
    {
        // Stop takes nothing new on, but the dispatcher had already handed two contents to the queue.
        // Workers keep being started for those: otherwise they would sit claimed until they went
        // stale, and each would cost its content an attempt for work nobody was doing.
        $status = app(ConverterStatus::class);
        $status->set(ConverterStatus::RUNNING, 2);
        $status->set(ConverterStatus::STOPPED);

        Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'status' => ConversionStatus::Claimed,
            'worker' => 'worker-1',
            'claimed_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $this->artisan('converters:supervise', ['--passes' => 1])->assertSuccessful();

        Process::assertRanTimes($this->ran('queue:work'), 2);
    }

    public function test_it_takes_back_what_the_machine_was_converting_when_it_stopped(): void
    {
        // A reboot kills every worker, and their contents stay claimed with nobody working on them.
        // Holding the supervisor role means no pool of ours is running, so they are put back at once
        // instead of waiting out the staleness window - an hour and a half of a machine doing nothing.
        $archive = new FakeArchive;
        $archive->addContent('C1')->reserve('C1', 'worker-1');
        $this->app->instance(ArchiveGateway::class, $archive);
        $this->app->instance(FileStore::class, new LocalFileStore(sys_get_temp_dir()));

        $queue = new ConversionQueue;
        $queue->add([new DiscoveredContent('C1', 1, null)]);
        $interrupted = $queue->claim('worker-1', 1)->sole();
        $interrupted->forceFill(['heartbeat_at' => now()->subMinutes(30)])->save();

        $this->artisan('converters:supervise', ['--passes' => 1])->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $interrupted->refresh()->status);
        $this->assertNull($archive->ownerOf('C1'));
    }

    public function test_a_second_supervisor_refuses_rather_than_running_a_second_pool(): void
    {
        // Two supervisors would run two pools: eight workers while the panel says four, each claiming
        // work. The role is held in the cache every process of the panel shares.
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 4);
        Cache::put('converter.supervisor', 'supervisor@another-host:123', 60);

        $this->artisan('converters:supervise', ['--passes' => 1])
            ->expectsOutputToContain('is already running this panel')
            ->assertFailed();

        Process::assertNothingRan();
    }

    public function test_it_gives_the_role_back_when_it_stops(): void
    {
        app(ConverterStatus::class)->set(ConverterStatus::RUNNING, 1);

        $this->artisan('converters:supervise', ['--passes' => 1])->assertSuccessful();

        // Otherwise the next supervisor - a restart after a Windows update, say - would have to wait
        // for the role to expire before it started anything.
        $this->assertNull(Cache::get('converter.supervisor'));
    }

    /**
     * A truth test for "this artisan command was started", whatever else is on its command line.
     */
    private function ran(string $command): callable
    {
        return function (PendingProcess $process) use ($command): bool {
            $line = is_array($process->command) ? implode(' ', $process->command) : (string) $process->command;

            return str_contains($line, $command);
        };
    }
}
