<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Says why the archive is not offering the contents older than a given date.
 *
 * Discovery orders by GeneralContent.ProcessDate and takes the oldest that still need converting, so
 * where a scan starts is a fact about the archive, not about the scan. But from outside, "the oldest
 * work is from July" and "the scan cannot see anything before July" look exactly the same - and only
 * one of them means documents are being missed.
 *
 * This counts the older contents against each of discovery's own conditions. If they are all
 * accounted for by RenderMediaId = 1 then they were converted long ago, by this pipeline or by the
 * C# one before it, and there is nothing to find. If a lot of them are reserved, that is the retired
 * pipeline's dead worker GUIDs holding contents nobody will ever release - which converters:retry
 * clears - and those ARE being missed.
 *
 * Read-only, and slow: one pass over a join of two tables holding 93 and 140 million rows.
 */
class ExplainDiscovery extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:explain-discovery
        {--before= : the date to look before (default: where the discovery watermark started this scan)}';

    /**
     * @var string
     */
    protected $description = 'Say why the archive is not offering the contents older than a date';

    public function handle(ArchiveGateway $archive): int
    {
        $before = $this->before();

        if ($before === null) {
            return self::FAILURE;
        }

        $this->line(sprintf('Asking the archive about the contents processed before %s.', $before->format('Y-m-d H:i:s')));
        $this->line('  This reads a join of GeneralContent and MVDContent in one pass and takes a while.');
        $this->newLine();

        try {
            $counts = $archive->discoveryBreakdown($before);
        } catch (Throwable $exception) {
            $this->components->error('The archive could not be read: '.$exception->getMessage());

            return self::FAILURE;
        }

        if ($counts['total'] === 0) {
            $this->components->info('The archive holds no content with a PDF processed before that date at all.');
            $this->line('  So the date discovery starts from is simply where the archive\'s own history begins.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=yellow>contents with a PDF, processed before then</>', '<fg=yellow>'.number_format($counts['total']).'</>');
        $this->components->twoColumnDetail('already converted (RenderMediaId = 1)', $this->share($counts['converted'], $counts['total']));
        $this->components->twoColumnDetail('reserved by somebody (Reserved is not free)', $this->share($counts['reserved'], $counts['total']));
        $this->components->twoColumnDetail('held by the index threshold flag', $this->share($counts['threshold'], $counts['total']));
        $this->components->twoColumnDetail('<fg=yellow>discovery would offer these</>', '<fg=yellow>'.number_format($counts['offered']).'</>');

        $this->newLine();

        if ($counts['offered'] === 0 && $counts['reserved'] === 0) {
            $this->components->info('Nothing older is being missed.');
            $this->line('  Every content processed before that date is already converted, so discovery starting where');
            $this->line('  it does is the archive\'s own history rather than a blind spot.');
            $this->line('  ProcessDate is when the archive processed the content, which is not the document\'s own date:');
            $this->line('  a newspaper from 2022 indexed last month carries last month\'s ProcessDate.');

            return self::SUCCESS;
        }

        if ($counts['offered'] > 0) {
            $this->components->warn(sprintf(
                '%s content(s) older than that date would be offered, so discovery has not seen them.',
                number_format($counts['offered']),
            ));
            $this->line('  Run `php artisan converters:discover --all --restart` to walk the archive from the beginning.');
            $this->newLine();
        }

        // Counted as missed in its own right, and not folded into the line above. A reserved content
        // is not waiting to be discovered - it cannot be discovered, by this pipeline or any other,
        // until somebody puts the marker back. Rescanning the archive for ever would never find one.
        if ($counts['reserved'] > 0) {
            $this->components->warn(sprintf(
                '%s content(s) older than that date are reserved, and no scan can ever offer those.',
                number_format($counts['reserved']),
            ));
            $this->line('  GeneralContent.Reserved is both the lock and the verdict, and the retired C# pipeline left');
            $this->line('  its dead workers\' GUIDs in it. A content held that way is invisible to discovery for good.');
            $this->line('  `php artisan converters:retry` frees the ones this panel already has a row for; the rest');
            $this->line('  need the marker clearing in the archive before anything will pick them up.');
        }

        return self::SUCCESS;
    }

    private function before(): ?CarbonImmutable
    {
        $given = trim((string) $this->option('before'));

        if ($given === '') {
            $this->components->error('Name the date to look before, for example --before="2026-07-29 21:40:14".');
            $this->line('  Use the point discovery\'s first pass reached; anything older than that it has not offered.');

            return null;
        }

        try {
            return CarbonImmutable::parse($given);
        } catch (Throwable) {
            $this->components->error("\"{$given}\" is not a date this understands.");

            return null;
        }
    }

    private function share(int $count, int $total): string
    {
        return sprintf('%s  (%s%%)', number_format($count), $total === 0 ? '0' : round($count / $total * 100, 1));
    }
}
