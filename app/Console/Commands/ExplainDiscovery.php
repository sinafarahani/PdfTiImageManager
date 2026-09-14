<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Models\Conversion;
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

        $queued = Conversion::query()->count();

        $this->components->twoColumnDetail('<fg=yellow>contents with a PDF, processed before then</>', '<fg=yellow>'.number_format($counts['total']).'</>');
        $this->components->twoColumnDetail('<fg=yellow>of those, discovery would offer</>', '<fg=yellow>'.number_format($counts['offered']).'</>');

        $this->newLine();
        $this->line('  The rest, and why the archive is holding them back:');

        // Reported apart because they overlap almost exactly, and reporting them as three shares of
        // the total invites the reading that they are three separate populations. markConverted()
        // writes all three at once - RenderMediaId = 1, the converted marker, and the threshold bit -
        // so on a healthy archive these are three views of the same contents.
        $this->components->twoColumnDetail('    converted (RenderMediaId = 1)', number_format($counts['converted']));
        $this->components->twoColumnDetail('    carrying the archive\'s "converted" marker', number_format($counts['convertedMarker']));
        $this->components->twoColumnDetail('    carrying the "failed" marker', number_format($counts['failedMarker']));
        $this->components->twoColumnDetail('    held by the index threshold flag', number_format($counts['threshold']));
        $this->components->twoColumnDetail(
            '    <fg=red>reserved by a worker that is not coming back</>',
            '<fg=red>'.number_format($counts['heldByWorker']).'</>',
        );

        $this->newLine();
        $this->components->twoColumnDetail('the panel\'s queue holds, in total', number_format($queued));

        $this->newLine();

        if ($counts['offered'] === 0 && $counts['heldByWorker'] === 0) {
            $this->components->info('Nothing older is being missed.');
            $this->line('  Every content processed before that date is already converted, so discovery starting where');
            $this->line('  it does is the archive\'s own history rather than a blind spot.');
            $this->line('  ProcessDate is when the archive processed the content, which is not the document\'s own date:');
            $this->line('  a newspaper from 2022 indexed last month carries last month\'s ProcessDate.');

            return self::SUCCESS;
        }

        if ($counts['offered'] > 0) {
            // Deliberately not "discovery has not seen them". The archive offering a content says
            // nothing about whether the panel already has it: a content sits in the queue as Pending
            // for as long as it takes to convert, and the archive goes on offering it that whole
            // time. The two numbers together are the only honest reading.
            $this->components->warn(sprintf(
                'The archive would offer %s content(s) processed before that date.',
                number_format($counts['offered']),
            ));
            $this->line(sprintf(
                '  That is work still to do, not work that has been missed: a content stays offerable until it is'
                    .PHP_EOL.'  converted, and the panel\'s queue already holds %s.',
                number_format($queued),
            ));
            $this->line('  If the two are close, discovery has them all. If the archive\'s number is much larger,');
            $this->line('  `php artisan converters:discover --all --restart` walks the archive from the beginning');
            $this->line('  and queues whatever is not there - it queues nothing that already is.');
            $this->newLine();
        }

        // Counted as missed in its own right, and not folded into the line above. A reserved content
        // is not waiting to be discovered - it cannot be discovered, by this pipeline or any other,
        // until somebody puts the marker back. Rescanning the archive for ever would never find one.
        if ($counts['heldByWorker'] > 0) {
            $this->components->warn(sprintf(
                '%s content(s) are held by a worker GUID, and no scan can ever offer those.',
                number_format($counts['heldByWorker']),
            ));
            $this->line('  GeneralContent.Reserved is both the lock and the verdict. These carry neither the converted');
            $this->line('  marker nor the failed one, so they are not a verdict about anything - they are contents a');
            $this->line('  worker took and never gave back, and the retired C# pipeline left them by the thousand.');
            $this->line('  A content held that way is invisible to discovery for good.');
            $this->line('  `php artisan converters:retry` frees the ones this panel already has a row for.');
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
