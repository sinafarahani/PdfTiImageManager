<?php

namespace App\Console\Commands;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
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
    protected $signature = 'converters:discover
        {--batch= : Contents to pull in one pass (default: converter.discovery.batch)}
        {--all : Keep passing until the archive has nothing left to offer, instead of stopping after one}';

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

    /**
     * Contents queued across every pass this run made.
     */
    private int $added = 0;

    /**
     * What the last pass actually saw, so that a pass which cannot move the watermark can say why
     * rather than only that it happened.
     *
     * @var array{found: int, missing: int, firstMissing: ?string, oldest: ?string, newest: ?string, advanceTo: ?string}
     */
    private array $lastPass = [
        'found' => 0,
        'missing' => 0,
        'firstMissing' => null,
        'oldest' => null,
        'newest' => null,
        'advanceTo' => null,
    ];

    public function handle(ArchiveGateway $archive, ConversionQueue $queue): int
    {
        // Artisan keeps one instance of a command and runs it again, so these have to be cleared per
        // invocation or a second run reports the first run's totals on top of its own.
        $this->added = 0;
        $this->dropped = 0;

        $batch = (int) ($this->option('batch') ?: config('converter.discovery.batch'));

        if ($batch < 1) {
            $this->error('The batch size must be at least 1.');

            return self::FAILURE;
        }

        try {
            if (! $this->seeded() && (bool) config('converter.discovery.seed_from_pdfconvert')) {
                $this->seed($archive, $queue, $batch);
            } elseif ($this->option('all')) {
                $this->scanEverything($archive, $queue, $batch);
            } else {
                $this->scan($archive, $queue, $batch);
            }
        } catch (QueryException $exception) {
            // This runs from the scheduler every converter.discovery.interval_minutes, so an archive
            // that is unreachable or not configured yet must leave one line somebody can act on
            // instead of a stack trace, over and over, in the scheduler's output. Nothing was queued
            // and nothing was written: the watermark is only ever moved after a pass has read the
            // archive, so the next pass covers exactly the same range again.
            //
            // Reported as well as printed, because schedule:run sends a scheduled command's output
            // to NUL: the log is the only place a pass that failed at 03:00 can still be seen.
            report($exception);

            $this->error('The archive could not be read: '.$this->driverMessage($exception));
            $this->line('Nothing was queued and the discovery watermark was left where it is.');
            $this->line('Check the ARCHIVE_DB_* settings in .env and that this machine may reach the archive; `php artisan converters:preflight` tests the connection on its own.');

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Queued %d new content(s); %d waiting, %d in the queue in total.',
            $this->added,
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
    private function seed(ArchiveGateway $archive, ConversionQueue $queue, int $batch): void
    {
        $this->info('Seeding the queue from the old pipeline (PdfConvert).');

        $offset = 0;

        while (true) {
            $page = $archive->seedFromLegacyQueue($batch, $offset);

            if ($page === []) {
                break;
            }

            $this->added += $queue->add($page);

            $this->reportDropped($queue->missing($page));

            $offset += count($page);

            if (count($page) < $batch) {
                break;
            }
        }

        // Written only once the whole legacy queue has been read: a seed that died halfway through has
        // to run again, and re-running it costs nothing because add() ignores what is already queued.
        $this->markSeeded();
    }

    /**
     * Passes until the archive has nothing left to offer.
     *
     * A pass takes at most one batch, which is what the scheduler wants: a fixed, modest amount of
     * work every quarter of an hour, for ever. It is not what somebody filling an empty queue wants,
     * because at 5,000 a pass and a pass every fifteen minutes, a million contents is ten days of
     * waiting for a scan the archive could finish in an afternoon.
     *
     * The watermark makes this safe to stop and start: each pass resumes where the last one left off,
     * so an interrupted run loses nothing, and a pass that runs at the same time as the scheduler's
     * own costs only a little repeated reading - add() ignores contents already queued and the
     * watermark never moves backwards.
     */
    private function scanEverything(ArchiveGateway $archive, ConversionQueue $queue, int $batch): void
    {
        $pass = 0;

        while (true) {
            $before = $this->processedUntil();
            $found = $this->scan($archive, $queue, $batch);
            $pass++;

            if ($found === 0) {
                $this->line(sprintf('Pass %d found nothing; the archive has nothing further to offer.', $pass));

                return;
            }

            $this->line(sprintf(
                '  pass %d: %s content(s) read, %s queued so far, watermark at %s',
                $pass,
                number_format($found),
                number_format($this->added),
                $this->processedUntil()?->format('Y-m-d H:i:s') ?? 'the beginning',
            ));

            if ($this->dropped > 0) {
                // reportDropped() has already said which contents and why. Carrying on would walk
                // past them, and the watermark is deliberately still where it was.
                $this->error('Stopping: the last pass could not queue everything it read.');

                return;
            }

            // The one way this loop could run for ever: a pass that keeps reading contents but
            // cannot move the watermark past them - every ProcessDate null, say. Reading the same
            // batch again would do nothing but load the archive.
            if (! $this->moved($before)) {
                // The overlap rewound the window far enough back that it holds more than one batch,
                // so every pass reads the same contents again and the watermark never gets past them.
                // Harmless once every quarter of an hour; fatal in a loop. One pass without the
                // rewind steps over them, and the next pass has its overlap back.
                $before = $this->processedUntil();
                $found = $this->scan($archive, $queue, $batch, rewind: false);
                $pass++;

                if ($found === 0) {
                    // Everything the last pass read was inside the overlap and already queued, and
                    // there is nothing beyond the watermark at all. That is the far end, not a stall.
                    $this->line(sprintf('Pass %d found nothing beyond the watermark; the archive has nothing further to offer.', $pass));

                    return;
                }

                if (! $this->moved($before) && ! $this->nudgePastWhatWasRead()) {
                    $this->explainTheStall();

                    return;
                }
            }
        }
    }

    /**
     * Moves the watermark a hair past the newest ProcessDate the last pass read, and says whether it
     * could.
     *
     * The last resort against the scan wedging, which is the one failure that costs documents: a
     * watermark that cannot move is a discovery that never finds anything again, quietly, for ever.
     * It is only safe because of what the caller has already established - every content the pass
     * read is provably in the queue - so stepping a microsecond past the newest of them cannot step
     * over anything that was not stored. The overlap re-reads that ground on the next pass anyway.
     *
     * It should now be unreachable: the watermark keeps its fraction, so the ordinary comparison
     * moves it. It stays because the cost of being wrong about that is a silent stall.
     */
    private function nudgePastWhatWasRead(): bool
    {
        if ($this->lastPass['missing'] > 0 || $this->lastPass['newest'] === null) {
            return false;
        }

        $past = CarbonImmutable::parse($this->lastPass['newest'])->addMicrosecond();

        DB::table('conversion_watermarks')
            ->where('name', self::WATERMARK)
            ->update(['processed_until' => $past->format('Y-m-d H:i:s.u'), 'updated_at' => now()]);

        $this->warn(sprintf(
            'The watermark could not be moved by the usual comparison, so it was stepped past %s; every content read was already queued.',
            $this->lastPass['newest'],
        ));

        return true;
    }

    /**
     * Says what the pass that could not advance actually saw.
     *
     * "Could not advance the watermark" on its own is a dead end: it is true of several quite
     * different situations and tells nobody which one they are in. The watermark only moves over
     * contents that are provably in the queue, so it stops for exactly one of two reasons - the
     * archive offered a content this panel could not store, or it offered nothing with a ProcessDate
     * beyond the mark - and the numbers say which.
     */
    private function explainTheStall(): void
    {
        $this->warn('Stopping: the last pass read contents but could not advance the watermark past them.');

        $this->components->twoColumnDetail('the watermark is at', $this->processedUntil()?->format('Y-m-d H:i:s') ?? 'the beginning');
        $this->components->twoColumnDetail('contents the archive offered', (string) $this->lastPass['found']);
        $this->components->twoColumnDetail('their ProcessDates', ($this->lastPass['oldest'] ?? '-').' to '.($this->lastPass['newest'] ?? '-'));
        $this->components->twoColumnDetail('of those, not in the queue afterwards', (string) $this->lastPass['missing']);

        if ($this->lastPass['firstMissing'] !== null) {
            $this->components->twoColumnDetail('the first of them', (string) $this->lastPass['firstMissing']);
        }

        $this->components->twoColumnDetail('the furthest the watermark could go', $this->lastPass['advanceTo'] ?? 'nowhere');

        $this->newLine();

        if ($this->lastPass['missing'] > 0) {
            $this->line('  The archive offered a content that is not in the queue afterwards, so the watermark stopped');
            $this->line('  at it deliberately: moving past a content that was never stored would hide that document for');
            $this->line('  good. Look at why that one content will not insert - it is named above.');

            return;
        }

        if ($this->lastPass['advanceTo'] !== null) {
            $this->line('  The watermark is already at or beyond the newest ProcessDate the archive offered, so there is');
            $this->line('  nothing further forward to move to. That is the end of the scan rather than a fault.');

            return;
        }

        $this->line('  Every content offered has no ProcessDate at all, so there is nothing to move the watermark to.');
        $this->line('  Those contents are queued if they were new; they simply cannot advance the scan.');
    }

    /**
     * Whether the watermark has moved on from where it was.
     */
    private function moved(?CarbonImmutable $from): bool
    {
        return $this->processedUntil()?->format('Y-m-d H:i:s.u') !== $from?->format('Y-m-d H:i:s.u');
    }

    /**
     * One incremental pass: everything the archive has processed since the watermark. Returns how
     * many contents the archive offered, which is what says whether there is any point asking again.
     */
    private function scan(ArchiveGateway $archive, ConversionQueue $queue, int $batch, bool $rewind = true): int
    {
        $overlap = $rewind ? (int) config('converter.discovery.overlap_minutes') : 0;
        $processedUntil = $this->processedUntil();

        // The window is rewound before every pass. A content inserted while the previous pass was
        // running carries a ProcessDate inside the range that pass already reported and would never
        // be looked at again; re-reporting contents costs nothing, because add() ignores the ones
        // already queued. The rewind is also what covers a batch cut in the middle of a run of
        // contents that share one ProcessDate, which the scan cannot see the far side of.
        $found = $archive->discover($processedUntil?->subMinutes($overlap), $batch);

        $this->added += $queue->add($found);

        $missing = $queue->missing($found);

        $this->reportDropped($missing);

        $advanceTo = $this->watermarkFor($found, $missing);

        $dates = array_values(array_filter(array_map(
            fn (DiscoveredContent $content): ?string => $content->processDate?->format('Y-m-d H:i:s.u'),
            $found,
        )));

        $this->lastPass = [
            'found' => count($found),
            'missing' => count($missing),
            'firstMissing' => $missing[0] ?? null,
            'oldest' => $dates === [] ? null : $dates[0],
            'newest' => $dates === [] ? null : max($dates),
            'advanceTo' => $advanceTo?->format('Y-m-d H:i:s'),
        ];

        $this->advanceTo($advanceTo);

        return count($found);
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
     * What is wrong, without the statement that hit it. A QueryException's own message repeats the
     * whole SQL and its bindings - useful in a log, unreadable as the one line a scheduled command
     * leaves behind - while the driver's message underneath it is the part that names the cause.
     */
    private function driverMessage(QueryException $exception): string
    {
        return $exception->getPrevious()?->getMessage() ?: $exception->getMessage();
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

        // Kept to the microsecond. Truncating to the second is what wedged the scan: a content
        // processed at 08:15:37.123 was recorded as 08:15:37, came back on the next pass because
        // .123 is later than .000, and truncated to the same second again.
        $processedUntil = $newest->format('Y-m-d H:i:s.u');

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
