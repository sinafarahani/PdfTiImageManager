<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class MonitorFreezeParent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:freezes';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check if a process is frozen (>30min) and handle termination + restart';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $threads = Cache::get('action_threads', 1);
        for ($i = 0; $i < $threads; $i++) {
            Log::info('running freeze for' . $i);
            $this->call('monitor-freeze', ['folder' => $i]);
        }
    }
}
