<?php

namespace Tests\Feature\Jobs;

use App\Actions\Converter\ConverterStatus;
use App\Actions\Converter\WorkerProcesses;
use App\Jobs\RunJob;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class RunJobTest extends TestCase
{
    private string $folder;

    protected function setUp(): void
    {
        parent::setUp();

        // Worker folder 3 right after a Stop: status.txt still asks the bridge to finish.
        $this->folder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-runjob-'.uniqid().DIRECTORY_SEPARATOR.'3';
        File::ensureDirectoryExists($this->folder);
        File::put($this->folder.DIRECTORY_SEPARATOR.'status.txt', 'terminate');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->folder));

        parent::tearDown();
    }

    public function test_does_not_start_a_worker_when_the_converters_were_stopped_after_the_start(): void
    {
        $converterStatus = app(ConverterStatus::class);
        $startVersion = $converterStatus->set(ConverterStatus::RUNNING, 4);
        $converterStatus->set(ConverterStatus::STOPPED);
        Process::preventStrayProcesses();
        Process::fake(['*tasklist*' => 'INFO: No tasks are running which match the specified criteria.']);

        (new RunJob($this->folder, 4, 'C:\\runner\\Forms_Runner.exe', $startVersion))->handle($converterStatus, app(WorkerProcesses::class));

        $this->assertStringEqualsFile($this->folder.DIRECTORY_SEPARATOR.'status.txt', 'terminate');
        $this->assertFileDoesNotExist($this->folder.DIRECTORY_SEPARATOR.'share.txt');
    }

    public function test_waits_for_the_previous_run_to_exit_and_gives_up_when_stopped_meanwhile(): void
    {
        $converterStatus = app(ConverterStatus::class);
        $startVersion = $converterStatus->set(ConverterStatus::RUNNING, 4);
        Process::preventStrayProcesses();
        Process::fake(['*tasklist*' => '"e3.exe","4242","Console","1","10,240 K"']);
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $converterStatus->set(ConverterStatus::STOPPED));

        (new RunJob($this->folder, 4, 'C:\\runner\\Forms_Runner.exe', $startVersion))->handle($converterStatus, app(WorkerProcesses::class));

        Sleep::assertSleptTimes(1);
        Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command[0] === 'taskkill');
        $this->assertStringEqualsFile($this->folder.DIRECTORY_SEPARATOR.'status.txt', 'terminate');
        $this->assertFileDoesNotExist($this->folder.DIRECTORY_SEPARATOR.'share.txt');
    }

    public function test_ends_a_previous_run_that_is_still_there_long_after_the_stop(): void
    {
        touch($this->folder.DIRECTORY_SEPARATOR.'status.txt', now()->subMinutes(11)->getTimestamp());
        $converterStatus = app(ConverterStatus::class);
        $startVersion = $converterStatus->set(ConverterStatus::RUNNING, 4);
        Process::preventStrayProcesses();
        Process::fake([
            '*taskkill*' => Process::result(),
            '*e3.exe*' => '"e3.exe","4242","Console","1","10,240 K"',
            '*3.exe*' => '"3.exe","4343","Console","1","80,512 K"',
        ]);
        Sleep::fake();
        Sleep::whenFakingSleep(fn () => $converterStatus->set(ConverterStatus::STOPPED));

        (new RunJob($this->folder, 4, 'C:\\runner\\Forms_Runner.exe', $startVersion))->handle($converterStatus, app(WorkerProcesses::class));

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['taskkill', '/F', '/T', '/PID', '4343']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['taskkill', '/F', '/T', '/PID', '4242']);
    }
}
