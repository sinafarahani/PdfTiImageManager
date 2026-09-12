<?php

namespace App\Console\Commands;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Console\Command;

/**
 * Takes the contents of the profiles named in CONVERTER_SKIP_PROFILES out of the queue.
 *
 * Discovery stops offering those profiles as soon as they are named, but whatever was queued before
 * that is still there, and a profile whose documents have been removed from the file store is the
 * expensive kind: every one of its contents has to be carried to a worker, connected to the FTP site
 * and asked for by name before the pipeline can learn what the setting already says. At a second or
 * two each, a few hundred thousand of them is a week of converting nothing.
 *
 * This does the same thing in one statement per batch. Nothing in the archive is touched and nothing
 * is uploaded or deleted: the contents are only marked cancelled in the panel's own queue, so the
 * archive is left exactly as it was and removing the profile from the setting puts them all back
 * (converters:retry --stage=skipped).
 *
 * It runs on the schedule as well, which is what makes naming a profile in .env enough on its own.
 */
class SkipProfiles extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:skip
        {--profile=* : Profile IDs to skip (default: converter.skip_profiles)}
        {--batch=5000 : Rows per statement}
        {--pretend : Count them without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Take the contents of profiles that are not converted out of the queue';

    public function handle(): int
    {
        $profiles = $this->profiles();

        if ($profiles === []) {
            // Not a failure: this runs on the schedule, and no profiles named is the normal state.
            $this->line('No profiles are configured as not converted (CONVERTER_SKIP_PROFILES is empty).');

            return self::SUCCESS;
        }

        $batch = max(1, (int) $this->option('batch'));

        // Contents already being converted are left alone. A worker holds that row, and taking it out
        // from under one would leave the archive reserved with nobody to release it; the job itself
        // recognises the profile and cancels it, so it is finished either way within the minute.
        $pending = fn () => Conversion::query()
            ->whereIn('profile_id', $profiles)
            ->whereIn('status', [ConversionStatus::Pending, ConversionStatus::Failed]);

        $waiting = (clone $pending())->where('status', ConversionStatus::Pending)->count();
        $failed = (clone $pending())->where('status', ConversionStatus::Failed)->count();
        $total = $waiting + $failed;

        $this->line(sprintf(
            'Profile(s) %s: %s waiting and %s already failed.',
            implode(', ', $profiles),
            number_format($waiting),
            number_format($failed),
        ));

        if ($total === 0) {
            $this->components->info('Nothing to take out of the queue.');

            return self::SUCCESS;
        }

        if ($this->option('pretend')) {
            $this->components->info(sprintf('%s content(s) would be taken out of the queue.', number_format($total)));

            return self::SUCCESS;
        }

        $reason = 'the profile is not converted (CONVERTER_SKIP_PROFILES)';
        $skipped = 0;

        // In batches so that a table of half a million rows is never held under one lock, and so that
        // a run interrupted half way leaves the rest for the next one rather than rolling back.
        do {
            // The batch is chosen by key and then updated by key, rather than with a LIMIT on the
            // UPDATE itself, because only MySQL accepts that form and the tests run on SQLite.
            $ids = $pending()->limit($batch)->pluck('id');

            $done = $ids->isEmpty() ? 0 : Conversion::query()->whereIn('id', $ids)->update([
                'status' => ConversionStatus::Cancelled->value,
                'failure_stage' => Stage::Skipped->value,
                'failure_reason' => $reason,
                'worker' => null,
                'claimed_at' => null,
                'heartbeat_at' => null,
                'finished_at' => now(),
                'updated_at' => now(),
            ]);

            $skipped += $done;

            if ($done > 0) {
                $this->line(sprintf('  %s of %s', number_format($skipped), number_format($total)));
            }
        } while ($done > 0);

        $this->components->info(sprintf(
            '%s content(s) of profile(s) %s were taken out of the queue. The archive was not touched.',
            number_format($skipped),
            implode(', ', $profiles),
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<int>
     */
    private function profiles(): array
    {
        /** @var list<string> $given */
        $given = (array) $this->option('profile');

        $profiles = $given === []
            ? (array) config('converter.skip_profiles')
            : array_map('intval', $given);

        return array_values(array_unique(array_filter(array_map('intval', $profiles))));
    }
}
