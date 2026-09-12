<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Fills the queue with contents the archive still needs converted.
 *
 * The old pipeline built its queue (PdfConvert) once, from a snapshot table that was never refreshed,
 * so every content added to the archive afterwards was invisible to it - about 48,500 of them by now,
 * which is the bug this command exists to fix. It runs on a schedule, scans by GeneralContent.ProcessDate
 * from a watermark it keeps itself, and can be run at any time: contents already in the queue are ignored.
 *
 * The watermark only ever moves over contents that are provably in the queue, and only forwards. Both
 * rules matter more than they look: discovery never looks back, so a content the watermark stepped
 * over is a document that can never be converted again.
 */
class DiscoverContents extends Command
{
    /**
     * @var string
     */
    protected $signature = 'converters:discover {--batch= : Contents to pull in this pass (default: converter.discovery.batch)}';

    /**
     * @var string
     */
    protected $description = 'Fill the conversion queue with contents the archive still needs converted';

    /**
     * The row of conversion_watermarks that holds how far the ProcessDate scan has come.
     */
    private const WATERMARK = 'discovery';

    /**
     * The row that records that the one-off seed from the old pipeline's queue has run. It is kept
     * apart from the watermark on purpose - see seed().
     */
    private const SEEDED = 'seeded-from-pdfconvert';

    /**
     * Contents a pass reported that did not end up in the queue. Any at all holds the watermark where
     * it is and fails the command, rather than let a silent insert failure hide a content for good.
     */
    private int $dropped = 0;

    public function handle(ArchiveGateway $archive, ConversionQueue $queue): int
    {
        $batch = (int) ($this->option('batch') ?: config('converter.discovery.batch'));

        if ($batch < 1) {
            $this->error('The batch size must be at least 1.');

            return self::FAILURE;
        }

        $added = ! $this->seeded() && (bool) config('converter.discovery.seed_from_pdfconvert')
            ? $this->seed($archive, $queue, $batch)
            : $this->scan($archive, $queue, $batch);

        $this->info(sprintf(
            'Queued %d new content(s); %d waiting, %d in the queue in total.',
            $added,
            Conversion::query()->pending()->count(),
            Conversion::query()->count(),
        ));

        return $this->dropped === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Fills an empty queue from the old pipeline's own queue. PdfConvert already lists the contents
     * that were waiting when it was built, so reading it is far cheaper than the DISTINCT join over
     * 93 million GeneralContent rows - and it is done exactly once, because the marker row this leaves
     * behind sends every later run down the scanning path.
     *
     * It deliberately does not move the watermark. PdfConvert.ProcessDate is not GeneralContent.ProcessDate:
     * it is whatever the old tooling copied into a heap years ago, and only for the contents it had
     * found and not yet claimed (state 0). Moving the scan's watermark up to the newest of those would
     * step over every content whose ProcessDate is older but which is not in that set - the 2,972
     * contents the old pipeline claimed and never finished among them - and none of those could ever
     * be discovered again. So the first scan still starts from the beginning; it walks forward one
     * batch at a time and costs nothing but a few passes, because the contents it finds are already
     * queued.
     */
    private function seed(ArchiveGateway $archive, ConversionQueue $queue, int $batch): int
    {
        $this->info('Seeding the queue from the old pipeline (PdfConvert).');

        $added = 0;
        $offset = 0;

        while (true) {
            $page = $archive->seedFromLegacyQueue($batch, $offset);

            if ($page === []) {
                break;
            }

            $added += $queue->add($page);

            $this->reportDropped($queue->missing($page));

            $offset += count($page);

            if (count($page) < $batch) {
                break;
            }
        }

        // Written only once the whole legacy queue has been read: a seed that died halfway through has
        // to run again, and re-running it costs nothing because add() ignores what is already queued.
        $this->markSeeded();

        return $added;
    }

    /**
     * One incremental pass: everything the archive has processed since the watermark.
     */
    private function scan(ArchiveGateway $archive, ConversionQueue $queue, int $batch): int
    {
        $overlap = (int) config('converter.discovery.overlap_minutes');
        $processedUntil = $this->processedUntil();

        // The window is rewound before every pass. A content inserted while the previous pass was
        // running carries a ProcessDate inside the range that pass already reported and would never
        // be looked at again; re-reporting contents costs nothing, because add() ignores the ones
        // already queued. The rewind is also what covers a batch cut in the middle of a run of
        // contents that share one ProcessDate, which the scan cannot see the far side of.
        $found = $archive->discover($processedUntil?->subMinutes($overlap), $batch);

        $added = $queue->add($found);

        $missing = $queue->missing($found);

        $this->reportDropped($missing);

        $this->advanceTo($this->watermarkFor($found, $missing));

        return $added;
    }

    /**
     * How far the watermark may safely be moved: the batch comes back oldest first, so the walk stops
     * at the first content that is not in the queue and everything from there on stays inside the
     * range of the next pass.
     *
     * @param  list<DiscoveredContent>  $contents
     * @param  list<string>  $missing
     */
    private function watermarkFor(array $contents, array $missing): ?CarbonImmutable
    {
        $unstored = array_flip($missing);
        $newest = null;

        foreach ($contents as $content) {
            if (isset($unstored[$content->contentId])) {
                break;
            }

            if ($content->processDate !== null && ($newest === null || $content->processDate->greaterThan($newest))) {
                $newest = $content->processDate;
            }
        }

        return $newest;
    }

    /**
     * @param  list<string>  $missing
     */
    private function reportDropped(array $missing): void
    {
        if ($missing === []) {
            return;
        }

        $this->dropped += count($missing);

        $this->error(sprintf(
            '%d content(s) the archive reported are not in the queue (first: %s). The watermark was left where it is.',
            count($missing),
            $missing[0],
        ));
    }

    /**
     * Writes the watermark, never backwards: the overlap re-scan returns contents older than the point
     * already reached, and letting those drag it back would make every pass scan a little further into
     * the past than the last one. The condition is in the UPDATE rather than in PHP so that two passes
     * running at once cannot write back a value one of them read before the other moved it.
     */
    private function advanceTo(?CarbonImmutable $newest): void
    {
        DB::table('conversion_watermarks')->insertOrIgnore([
            'name' => self::WATERMARK,
            'processed_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        if ($newest === null) {
            return;
        }

        $processedUntil = $newest->format('Y-m-d H:i:s');

        DB::table('conversion_watermarks')
            ->where('name', self::WATERMARK)
            ->where(function (Builder $query) use ($processedUntil): void {
                $query->whereNull('processed_until')->orWhere('processed_until', '<', $processedUntil);
            })
            ->update(['processed_until' => $processedUntil, 'updated_at' => now()]);
    }

    private function markSeeded(): void
    {
        DB::table('conversion_watermarks')->insertOrIgnore([
            'name' => self::SEEDED,
            'processed_until' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seeded(): bool
    {
        return DB::table('conversion_watermarks')->where('name', self::SEEDED)->exists();
    }

    private function processedUntil(): ?CarbonImmutable
    {
        /** @var stdClass|null $watermark */
        $watermark = DB::table('conversion_watermarks')->where('name', self::WATERMARK)->first();

        return $watermark?->processed_until === null ? null : CarbonImmutable::parse($watermark->processed_until);
    }
}
