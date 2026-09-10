<?php

namespace Tests\Feature\Console;

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class EndFrozenWorkersTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-frozen-'.uniqid();
        config(['app.pdfToImg' => $this->root, 'app.forceStopAfterMinutes' => 10]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_ends_a_worker_still_running_10_minutes_after_the_stop(): void
    {
        $this->worker(3, 'terminate', minutesAgo: 11);
        Process::preventStrayProcesses();
        Process::fake([
            '*taskkill*' => Process::result(),
            '*e3.exe*' => '"e3.exe","4242","Console","1","10,240 K"',
            '*3.exe*' => '"3.exe","4343","Console","1","80,512 K"',
        ]);

        $this->artisan('converters:end-frozen')->assertSuccessful();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['taskkill', '/F', '/T', '/PID', '4343']);
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === ['taskkill', '/F', '/T', '/PID', '4242']);
    }

    public function test_leaves_workers_that_are_working_still_finishing_or_gone_alone(): void
    {
        $this->worker(1, '', minutesAgo: 60);
        $this->worker(2, 'terminate', minutesAgo: 5);
        $this->worker(3, 'terminate', minutesAgo: 30);
        Process::preventStrayProcesses();
        Process::fake([
            '*e1.exe*' => '"e1.exe","1111","Console","1","10,240 K"',
            '*e2.exe*' => '"e2.exe","2222","Console","1","10,240 K"',
            '*tasklist*' => 'INFO: No tasks are running which match the specified criteria.',
        ]);

        $this->artisan('converters:end-frozen')->assertSuccessful();

        Process::assertDidntRun(fn (PendingProcess $process): bool => $process->command[0] === 'taskkill');
    }

    /**
     * Creates worker folder $index whose status.txt was written $minutesAgo minutes ago.
     */
    private function worker(int $index, string $status, int $minutesAgo): void
    {
        $statusFile = $this->root.DIRECTORY_SEPARATOR.$index.DIRECTORY_SEPARATOR.'status.txt';
        File::ensureDirectoryExists(dirname($statusFile));
        File::put($statusFile, $status);
        touch($statusFile, now()->subMinutes($minutesAgo)->getTimestamp());
    }
}
