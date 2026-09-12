<?php

namespace App\Console\Commands;

use App\Actions\Converter\Pipeline\ContentWorkspace;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Deletes the workspace folders nothing is using any more.
 *
 * ContentWorkspace deletes a content's folder as soon as its pages are stored, and it retries a few
 * times when Windows still holds a handle - but a worker that is killed mid-conversion (the Stop
 * button, a power cut, the machine rebooting) never gets that far, and its folder stays on the staging
 * drive with a PDF and a document's worth of 300 dpi page images in it. The previous pipeline only
 * cleaned up in a branch that could not be reached, filled the drive, and lost 206 contents to "there
 * is not enough space on the disk"; this command is what keeps that from happening again.
 *
 * Two rules decide what may go, and both have to hold:
 *
 *  - the folder belongs to no conversion that is still claimed. A claimed conversion is one a worker
 *    says it is working on, and deleting the folder underneath it would fail that content,
 *  - the folder has not been touched for converter.workspace.sweep_after_minutes. A worker writes into
 *    its folder throughout a conversion, so a fresh folder is work in progress even if the claim is
 *    not visible from here yet - the row is written before the folder, but a conversion claimed on
 *    another machine against a different panel database would not be in ours at all.
 */
class SweepWorkspaces extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:sweep
        {--minutes= : Delete folders older than this (default: converter.workspace.sweep_after_minutes)}
        {--dry-run : Report what would be deleted and delete nothing}';

    /**
     * @var string
     */
    protected $description = 'Delete workspace folders left behind by an interrupted conversion';

    public function handle(ContentWorkspace $workspace): int
    {
        $minutes = (int) ($this->option('minutes') ?? config('converter.workspace.sweep_after_minutes'));

        if ($minutes < 1) {
            $this->error('The age must be at least 1 minute; a sweep with no delay would delete the folder of a conversion that has only just started.');

            return self::FAILURE;
        }

        $root = $workspace->root();

        if (! File::isDirectory($root)) {
            // Not a failure: the workspace is created when the first content is converted, so a panel
            // that has not run yet has nothing here.
            $this->info("The workspace {$root} does not exist yet; nothing to sweep.");

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $oldest = now()->subMinutes($minutes)->getTimestamp();
        $busy = $this->claimedContents();

        $deleted = 0;
        $kept = 0;
        $failed = 0;

        foreach (File::directories($root) as $folder) {
            // The folder is named after the content (ContentWorkspace::pathFor lower-cases the id), so
            // the name is what ties it to a conversion.
            $name = basename($folder);

            if (isset($busy[strtolower($name)])) {
                $kept++;
                $this->line("  kept {$name}: a worker is converting it.");

                continue;
            }

            // The directory's own timestamp: a rendered page written into it moves it, so this is when
            // work last happened there rather than when the folder was created.
            clearstatcache(true, $folder);
            $touched = @filemtime($folder);

            if ($touched !== false && $touched > $oldest) {
                $kept++;
                $this->line("  kept {$name}: touched less than {$minutes} minute(s) ago.");

                continue;
            }

            if ($dryRun) {
                $deleted++;
                $this->warn("  would delete {$name} (".$this->size($folder).').');

                continue;
            }

            if (File::deleteDirectory($folder)) {
                $deleted++;
                $this->warn("  deleted {$name}.");

                continue;
            }

            // A folder something still has open. Reported rather than thrown: the next hourly run gets
            // it, and a sweep that failed the whole command over one locked file would hide the rest.
            $failed++;
            $this->error("  {$name} could not be deleted; it is still in use.");
        }

        $this->info(sprintf(
            '%s %d workspace folder(s) in %s; %d kept, %d could not be deleted. %.1f GB free.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $root,
            $kept,
            $failed,
            $workspace->freeSpaceGb() ?? 0.0,
        ));

        return self::SUCCESS;
    }

    /**
     * The contents a worker is converting right now, lower-cased, in the shape the folder names have.
     *
     * @return array<string, true>
     */
    private function claimedContents(): array
    {
        /** @var list<string> $claimed */
        $claimed = Conversion::query()->claimed()->pluck('content_id')->all();

        $busy = [];

        foreach ($claimed as $contentId) {
            $busy[strtolower($contentId)] = true;
        }

        return $busy;
    }

    /**
     * What the folder holds, for the line that reports it. Only ever used for a message, so a file
     * that disappears while it is being counted must not matter.
     */
    private function size(string $folder): string
    {
        $bytes = 0;

        foreach (File::allFiles($folder, hidden: true) as $file) {
            $bytes += (int) @$file->getSize();
        }

        return sprintf('%.1f MB', $bytes / 1024 ** 2);
    }
}
