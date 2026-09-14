<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Console\Commands\Concerns\WalksTheArchive;
use App\Models\PurgedSource;
use Illuminate\Console\Command;
use Throwable;

/**
 * Removes the source rows of one deleted profile whose PDF files are gone, flagged deleted or not.
 *
 * This is a command of its own rather than a flag on converters:prune-orphans, and deliberately so.
 * That command only ever touches rows the archive has already flagged Deleted = 1 - rows it has
 * retired, whose documents it no longer offers - and that flag is most of what makes deleting from
 * MVDContent defensible. This one gives that up. The rows it is for were never converted, so nothing
 * ever flagged them: they are Deleted = 0, and the archive still believes it is offering those
 * documents. It only stops being a loss because the profile itself is gone and its files were
 * deleted wholesale long ago.
 *
 * So it is narrow on purpose:
 *
 *  - the profile has to be named, every time. There is no default;
 *  - it has to be one of CONVERTER_SKIP_PROFILES, so a profile can only be stripped after it has
 *    already been declared dead in .env and the converters have stopped offering it;
 *  - the DELETE itself joins GeneralContent and matches on ProfileID, so a row of any other profile
 *    is not reachable by this command whatever happens above;
 *  - a file is only ever called missing when the store actually answered and said so, and the store
 *    is probed before the walk and as it goes;
 *  - a row whose file is STILL THERE is never touched, whatever the profile.
 */
class PruneProfile extends Command
{
    use WalksTheArchive;

    /**
     * @var string
     */
    protected $signature = 'converters:prune-profile
        {--profile= : the ProfileID to strip, which must be one of CONVERTER_SKIP_PROFILES}
        {--restart : start the walk again from the beginning}
        {--limit=0 : most rows in one run, or 0 for every one of them}
        {--check=500 : rows a rehearsal examines, or 0 for every one of them}
        {--confirm : actually delete the rows}';

    /**
     * @var string
     */
    protected $description = 'Remove a deleted profile\'s source rows whose PDF files are gone';

    private int $rowsDeleted = 0;

    private int $filesStillThere = 0;

    private int $unreadable = 0;

    private int $checked = 0;

    public function handle(ArchiveGateway $archive, FileStore $store): int
    {
        $profileId = $this->profile();

        if ($profileId === null) {
            return self::FAILURE;
        }

        $confirmed = (bool) $this->option('confirm');

        if ($confirmed && ! $this->writesAreOn()) {
            $this->components->error('The archive is not accepting writes, so nothing was touched.');
            $this->line('  Set CONVERTER_WRITE_MODE=on in .env to let this run.');

            return self::FAILURE;
        }

        if (! $this->storeIsUp($store)) {
            return self::FAILURE;
        }

        $walk = 'prune-profile-'.$profileId;

        if ($this->option('restart')) {
            $this->forgetCursor($walk);
        }

        $budget = max(0, (int) $this->option($confirmed ? 'limit' : 'check'));
        $after = $this->cursor($walk);

        $this->line(sprintf(
            '%s the source rows of profile %d%s.',
            $confirmed ? 'Walking' : 'Rehearsing over',
            $profileId,
            $after === null ? ' (from the beginning)' : ", carrying on after {$after}",
        ));

        if (! $confirmed && $budget > 0) {
            $this->line(sprintf('  Stopping after %s row(s); --check=0 walks all of them.', number_format($budget)));
        }

        $stopped = false;

        while ($budget === 0 || $this->checked < $budget) {
            $page = $archive->profileSourcesAfter($profileId, $after, $budget === 0 ? 500 : min(500, $budget - $this->checked));

            if ($page === []) {
                if ($confirmed) {
                    $this->forgetCursor($walk);
                }

                break;
            }

            foreach ($page as $source) {
                if ($this->checked > 0 && $this->checked % self::PROBE_EVERY === 0 && ! $this->storeIsUp($store, quiet: true)) {
                    $this->components->error('The file store stopped answering, so the walk was stopped before it could mistake that for deleted files.');
                    $stopped = true;

                    break 2;
                }

                $this->examine($archive, $store, $source, $profileId, $confirmed);
                $this->checked++;
                $after = $source->mvdId;
            }

            if ($confirmed) {
                $this->rememberCursor($walk, (string) $after);
            }

            $this->line(sprintf(
                '  %s row(s) examined, %s removed, %s still have their file',
                number_format($this->checked),
                number_format($this->rowsDeleted),
                number_format($this->filesStillThere),
            ));
        }

        $this->report($profileId, $confirmed);

        if ($stopped) {
            $this->components->warn('The walk stopped early.');
        }

        return self::SUCCESS;
    }

    /**
     * The profile to strip, or null with the reason printed.
     *
     * Named every time, and only one that .env has already declared dead. Stripping a live profile's
     * rows would take documents the archive is still offering out of it on the strength of a missing
     * file, and that is a decision to make in .env, deliberately, not on a command line.
     */
    private function profile(): ?int
    {
        $given = trim((string) $this->option('profile'));

        if ($given === '' || ! ctype_digit($given)) {
            $this->components->error('Name the profile to strip, for example --profile=65.');

            return null;
        }

        $profileId = (int) $given;
        $skipped = array_map('intval', (array) config('converter.skip_profiles'));

        if (! in_array($profileId, $skipped, true)) {
            $this->components->error("Profile {$profileId} is not in CONVERTER_SKIP_PROFILES, so it is still being converted.");
            $this->line('  This command removes rows the archive is still offering documents for, on the strength of');
            $this->line('  a missing file. That is only defensible for a profile already declared dead, so add it to');
            $this->line('  CONVERTER_SKIP_PROFILES in .env first and let converters:skip retire its contents.');

            if ($skipped !== []) {
                $this->line('  Currently named there: '.implode(', ', $skipped).'.');
            }

            return null;
        }

        return $profileId;
    }

    private function examine(ArchiveGateway $archive, FileStore $store, SourceFile $source, int $profileId, bool $confirmed): void
    {
        $remotePath = $source->remoteFolder().'/'.$source->remoteFileName();

        try {
            $size = $store->size($remotePath);
        } catch (FileStoreException) {
            // Not evidence of anything. Left alone, counted, asked about again next run.
            $this->unreadable++;

            return;
        }

        if ($size !== null) {
            // The file is there, so the document is there, whatever the profile. Never touched.
            $this->filesStillThere++;

            return;
        }

        if (! $confirmed) {
            if ($this->rowsDeleted < 10) {
                $this->line(sprintf('    %s  %s', $source->contentId ?? '(unknown content)', $remotePath));
            }

            $this->rowsDeleted++;

            return;
        }

        $this->remove($archive, $source, $profileId, $remotePath);
    }

    private function remove(ArchiveGateway $archive, SourceFile $source, int $profileId, string $remotePath): void
    {
        $record = PurgedSource::query()->updateOrCreate(['mvd_id' => $source->mvdId], [
            'content_id' => (string) ($source->contentId ?? ''),
            'reason' => PurgedSource::ORPHANED,
            'conversion_id' => null,
            'remote_path' => $remotePath,
            'original_name' => $source->pageNo,
            'create_date_time' => $source->createDateTime,
            'bytes' => null,
            'file_deleted' => true,
        ]);

        try {
            $deleted = $archive->deleteProfileSource($source->mvdId, $profileId);
        } catch (Throwable $exception) {
            $record->delete();

            throw $exception;
        }

        if (! $deleted) {
            $record->delete();

            return;
        }

        $record->update(['row_deleted' => true]);
        $this->rowsDeleted++;
    }

    private function report(int $profileId, bool $confirmed): void
    {
        $this->newLine();
        $this->components->twoColumnDetail("source rows of profile {$profileId} examined", number_format($this->checked));
        $this->components->twoColumnDetail(
            $confirmed ? '<fg=yellow>rows removed</>' : '<fg=yellow>rows that would be removed</>',
            '<fg=yellow>'.number_format($this->rowsDeleted).'</>',
        );
        $this->components->twoColumnDetail('rows whose file is still there', number_format($this->filesStillThere));

        if ($this->unreadable > 0) {
            $this->components->twoColumnDetail('rows the store would not answer for', number_format($this->unreadable));
        }

        $this->newLine();

        if ($this->filesStillThere > 0) {
            $this->components->warn(sprintf(
                '%s of this profile\'s files are still on the store, so its documents are not all gone.',
                number_format($this->filesStillThere),
            ));
            $this->line('  Those rows were left alone. Worth knowing before treating the profile as dead.');
            $this->newLine();
        }

        if (! $confirmed) {
            $this->components->warn(sprintf('%s row(s) would be removed. Their files are already gone.', number_format($this->rowsDeleted)));
            $this->line('  Run it again with --confirm to do it.');
        } else {
            $this->components->info(sprintf('%s source row(s) of profile %d removed.', number_format($this->rowsDeleted), $profileId));
            $this->line('  Where it got to is remembered; run it again to carry on, or --restart to begin again.');
        }
    }
}
