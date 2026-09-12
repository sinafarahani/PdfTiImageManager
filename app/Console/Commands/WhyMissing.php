<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Console\Command;
use Throwable;

/**
 * Explains the contents whose source PDF was not on the file store, so that the cost of finding that
 * out can be judged before anything is changed to reduce it.
 *
 * Finding one out costs a whole FTP session - connect, log in, fail to download, list the folder, ask
 * for the folder itself - and there are hundreds of thousands of them, so the question worth asking
 * is whether they could have been recognised without asking the file store at all. Two answers:
 *
 *  - the paths cluster. The folder a file lives in comes from its own CreateDateTime, so whole days
 *    of the archive share a folder; if a day's folder is gone, every content of that day is missing
 *    and one question to the file store would have answered for all of them.
 *  - the archive already knew. A content whose PDF row is hidden or absent is recognised from the
 *    database alone, and never reaches the file store in the first place.
 *
 * Read-only: it reads the ledger and asks the archive about a sample, and writes nothing anywhere.
 */
class WhyMissing extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:why-missing
        {--sample=40 : contents to ask the archive about}
        {--folders=10 : folders to list}';

    /**
     * @var string
     */
    protected $description = 'Explain the contents whose source PDF was not on the file store';

    public function handle(ArchiveGateway $archive): int
    {
        $missing = Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->where('failure_stage', Stage::Missing);

        $total = (clone $missing)->count();

        if ($total === 0) {
            $this->components->info('No content has been recorded as having no source file.');

            return self::SUCCESS;
        }

        $this->folders($total, (int) $this->option('folders'));
        $this->archiveRows($archive, (int) $this->option('sample'));

        return self::SUCCESS;
    }

    /**
     * How the missing files are spread over the day folders of the file store. One question per folder
     * instead of one per file is only worth asking if the files share their folders.
     */
    private function folders(int $total, int $show): void
    {
        /** @var array<string, int> $counted */
        $counted = [];

        Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->where('failure_stage', Stage::Missing)
            ->select(['id', 'failure_reason'])
            ->chunkById(2000, function ($conversions) use (&$counted): void {
                foreach ($conversions as $conversion) {
                    $day = self::dayFolder($conversion->failure_reason);
                    $counted[$day] = ($counted[$day] ?? 0) + 1;
                }
            });

        arsort($counted);

        $folders = collect($counted)->map(
            fn (int $contents, string $day): object => (object) ['day_folder' => $day, 'contents' => $contents],
        )->values();

        $this->components->twoColumnDetail(
            '<fg=yellow>contents with no source file</>',
            '<fg=yellow>'.number_format($total).'</>',
        );
        $this->components->twoColumnDetail('day folders they are spread over', number_format($folders->count()));
        $this->components->twoColumnDetail(
            'contents per folder, on average',
            $folders->count() > 0 ? (string) round($total / $folders->count(), 1) : '-',
        );

        $this->newLine();
        $this->line('The fullest folders:');

        foreach ($folders->take(max(1, $show)) as $folder) {
            $this->line(sprintf('    %9s content(s) in %s', number_format((int) $folder->contents), $folder->day_folder));
        }

        // The reading that decides whether asking per folder is worth building: if most of the missing
        // files sit in folders that hold many of them, one question answers for all of that folder.
        $crowded = (int) $folders->filter(fn ($folder): bool => (int) $folder->contents >= 20)->sum('contents');

        $this->newLine();
        $this->components->twoColumnDetail(
            'in a folder holding 20 or more of them',
            sprintf('%s (%s%%)', number_format($crowded), round($crowded / $total * 100)),
        );
        $this->components->twoColumnDetail(
            'questions if the file store were asked per folder',
            sprintf('%s instead of %s', number_format($folders->count()), number_format($total)),
        );
    }

    /**
     * The day folder a recorded failure names. The reason ends in the path the download asked for -
     * "the source PDF is not on the file store: 2023/01/05/16/39/53/<id>.pdf" - and a file's folder
     * comes from its own CreateDateTime, so the first three segments are the day it was stored.
     */
    private static function dayFolder(?string $reason): string
    {
        $path = trim(substr((string) $reason, (int) strrpos((string) $reason, ': ') + 2));
        $segments = explode('/', $path);

        return count($segments) >= 4 ? implode('/', array_slice($segments, 0, 3)) : 'unrecognised';
    }

    /**
     * What the archive says about a sample of them, which tests the other explanation: that the rows
     * were hidden and the file store never needed asking.
     */
    private function archiveRows(ArchiveGateway $archive, int $sample): void
    {
        $contents = Conversion::query()
            ->where('status', ConversionStatus::Failed)
            ->where('failure_stage', Stage::Missing)
            ->inRandomOrder()
            ->limit(max(1, $sample))
            ->pluck('content_id');

        $this->newLine();
        $this->line(sprintf('Asking the archive about %d of them:', $contents->count()));
        $this->newLine();

        try {
            $site = $archive->currentFileSite();
        } catch (Throwable $exception) {
            $this->components->warn('The archive could not be reached, so only the folders above were read: '.$exception->getMessage());

            return;
        }

        $counts = ['live' => 0, 'hidden' => 0, 'none' => 0, 'elsewhere' => 0];

        foreach ($contents as $contentId) {
            $live = array_filter($archive->sourceFilesFor($contentId), fn (SourceFile $file): bool => $file->isPdf());

            if ($live === []) {
                $counts[$archive->hiddenSourcesFor($contentId) === [] ? 'none' : 'hidden']++;

                continue;
            }

            $counts['live']++;

            foreach ($live as $file) {
                if ($file->ftpSiteId !== null && $file->ftpSiteId !== $site->id) {
                    $counts['elsewhere']++;

                    break;
                }
            }
        }

        $this->components->twoColumnDetail('a live PDF row on site '.$site->id, (string) $counts['live']);
        $this->components->twoColumnDetail('only hidden PDF rows (Deleted = 1)', (string) $counts['hidden']);
        $this->components->twoColumnDetail('no PDF row at all', (string) $counts['none']);
        $this->components->twoColumnDetail('a live PDF row naming another FTP site', (string) $counts['elsewhere']);

        $this->newLine();

        if ($counts['hidden'] + $counts['none'] > 0) {
            $this->components->warn(
                'Some of these were recorded as having no file even though the archive has no live row for '
                .'them. That should not happen: a content with no live PDF row is refused before the file '
                .'store is opened. Worth looking at one of them.'
            );
        }

        if ($counts['elsewhere'] > 0) {
            $this->components->warn(
                'Some name an FTP site other than the current one. Their files are not missing - they are '
                .'being looked for on the wrong site.'
            );
        }

        if ($counts['hidden'] + $counts['none'] + $counts['elsewhere'] === 0) {
            $this->line('  Every one of them has a live PDF row on the current site, so the archive could not');
            $this->line('  have told us: the files really are gone and only the file store knows it.');
        }
    }
}
