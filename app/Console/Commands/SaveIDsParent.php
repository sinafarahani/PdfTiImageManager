<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SaveIDsParent extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'save:IDs';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'it save all the ids for healthy converted files';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $threads = Cache::get('action_threads', 1);
        for ($i = 0; $i < $threads; $i++) {
            Log::info('running save for' . $i);
            $this->call('save-IDs', ['folder' => $i]);
        }
    }
}
