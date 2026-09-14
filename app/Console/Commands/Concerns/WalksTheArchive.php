<?php

namespace App\Console\Commands\Concerns;

use App\Actions\Converter\Ftp\FileStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * What the two commands that remove archive rows on the strength of a missing file both need.
 *
 * Both rest on "the file is not there" meaning what it says, and a file store that is unreachable
 * says exactly that about every file on it. Both also walk MVDContent by its clustered key for hours,
 * so both need to be able to stop and carry on.
 */
trait WalksTheArchive
{
    /**
     * Contents between one health probe of the file store and the next. A store that goes down
     * mid-run must not be able to turn the rest of the run into a sweep of good rows.
     */
    private const PROBE_EVERY = 250;

    /**
     * Whether the file store is answering at all.
     *
     * Everything these commands do rests on a missing file being missing, and a store that has gone
     * away is indistinguishable from a store where everything has been deleted - unless you ask it
     * something it should always be able to answer.
     */
    private function storeIsUp(FileStore $store, bool $quiet = false): bool
    {
        try {
            $store->list('');
        } catch (Throwable $exception) {
            if (! $quiet) {
                $this->components->error('The file store could not be read, so nothing was touched: '.$exception->getMessage());
                $this->line('  Every file would look deleted, and this command would take the archive apart.');
            }

            return false;
        }

        return true;
    }

    /**
     * Where the last run of this walk stopped, as an MVDContent id.
     */
    private function cursor(string $name): ?string
    {
        $row = DB::table('conversion_watermarks')->where('name', $name)->first();

        return $row?->cursor === null || $row->cursor === '' ? null : (string) $row->cursor;
    }

    private function rememberCursor(string $name, string $mvdId): void
    {
        DB::table('conversion_watermarks')->updateOrInsert(
            ['name' => $name],
            ['cursor' => $mvdId, 'updated_at' => now(), 'created_at' => now()],
        );
    }

    private function forgetCursor(string $name): void
    {
        DB::table('conversion_watermarks')->where('name', $name)->update(['cursor' => null, 'updated_at' => now()]);
    }

    private function writesAreOn(): bool
    {
        return in_array(
            strtolower(trim((string) config('converter.archive.write_mode'))),
            ['on', 'true', '1', 'yes', 'enabled'],
            true,
        );
    }
}
