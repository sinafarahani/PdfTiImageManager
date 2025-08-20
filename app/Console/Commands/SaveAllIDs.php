<?php

namespace App\Console\Commands;

use App\Models\Freeze;
use App\Models\LastStop;
use App\Models\Successful;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class SaveAllIDs extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'save-IDs {folder}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'it save all the ids for healthy converted files';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $folderName = $this->argument('folder');

        if (File::exists("F:\\$folderName")) {
            $shareFolderPath = File::directories("F:\\$folderName");

            foreach ($shareFolderPath as $folder) {
                $pdfFiles = File::files($folder);
                foreach ($pdfFiles as $file) {
                    if (strtolower($file->getExtension()) === 'pdf') {
                        $mvdID = pathinfo($file->getFilename(), PATHINFO_FILENAME);
                        if(LastStop::where('MVD ID', $mvdID)->count()>0){
                            continue;
                        }
                        if(Freeze::where('MVD ID', $mvdID)->count()>0){
                            continue;
                        }
                        Successful::firstOrCreate([
                            'MVD ID' => $mvdID,
                        ]);
                    }
                }
            }
        }
    }
}
