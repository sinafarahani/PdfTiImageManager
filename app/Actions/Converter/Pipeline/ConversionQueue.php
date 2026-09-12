<?php

namespace App\Actions\Converter\Pipeline;

use App\Actions\Converter\Archive\DiscoveredContent;
use App\Models\Conversion;
use App\Models\ConversionPage;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

/**
 * The panel's work queue. The archive is not asked what to do next: scanning 93 million GeneralContent
 * rows for every worker is what made the old pipeline claim contents one at a time through a stored
 * procedure and deadlock against itself. Discovery fills this table instead, and a worker claims from
 * it - the archive is only touched once the work actually starts.
 *
 * Every state change here is written with a condition on the row's current state instead of through a
 * plain save(), and the three methods a worker calls while it holds a content say whether they were
 * applied. A worker that was merely slow must not be able to overwrite what the reconciler decided
 * about it, because by then the content may belong to somebody else.
 */
class ConversionQueue
{
    /**
     * Rows per INSERT. Discovery hands over half a million contents at a time, so they cannot all go
     * in one statement (nor be inserted one by one); this is small enough to stay well under the
     * placeholder limit of both drivers with six columns per row.
     */
    private const INSERT_CHUNK = 500;

    /**
     * Drivers whose SELECT can step over the rows another transaction holds. Laravel reports MariaDB
     * under its own driver name, so a check for "mysql" alone silently drops the lock clause on it -
     * and a claim without the clause has to be safe on its own anyway (see claim()).
     *
     * @var list<string>
     */
    private const SKIP_LOCKED_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /**
     * Adds contents that are not in the queue yet and returns how many were actually added. A content
     * already queued, already done or already failed is left exactly as it is: discovery re-scans an
     * overlapping window on every pass, so it hands over the same contents again and again, and a
     * second pass must not reset a conversion that is in flight.
     *
     * @param  iterable<DiscoveredContent>  $contents
     */
    public function add(iterable $contents): int
    {
        $added = 0;
        $now = now();

        /** @var array<string, array<string, mixed>> $chunk */
        $chunk = [];

        foreach ($contents as $content) {
            // Keyed by content id, so a batch that repeats a content does not depend on the database
            // to collapse it.
            $chunk[$content->contentId] = [
                'content_id' => $content->contentId,
                'profile_id' => $content->profileId,
                'status' => ConversionStatus::Pending->value,
                'attempts' => 0,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($chunk) >= self::INSERT_CHUNK) {
                $added += $this->insertIgnoringExisting($chunk);
                $chunk = [];
            }
        }

        return $added + ($chunk === [] ? 0 : $this->insertIgnoringExisting($chunk));
    }

    /**
     * The content ids that are not in the queue. Discovery asks this before it moves its watermark:
     * insertOrIgnore is an INSERT IGNORE on MySQL, which turns a row the columns cannot hold into a
     * warning rather than an error, and a watermark that stepped over such a content would hide it for
     * good - discovery only ever looks forward.
     *
     * @param  list<DiscoveredContent>  $contents
     * @return list<string>
     */
    public function missing(array $contents): array
    {
        $ids = array_values(array_unique(array_map(
            fn (DiscoveredContent $content): string => $content->contentId,
            $contents,
        )));

        $missing = [];

        foreach (array_chunk($ids, self::INSERT_CHUNK) as $chunk) {
            /** @var list<string> $present */
            $present = Conversion::query()->whereIn('content_id', $chunk)->pluck('content_id')->all();

            $missing = [...$missing, ...array_values(array_diff($chunk, $present))];
        }

        return $missing;
    }

    /**
     * Takes up to $limit of the oldest waiting contents for this worker. $worker identifies the
     * process (the panel uses host and pid), and it is what every later call proves ownership with.
     *
     * @return Collection<int, Conversion>
     */
    public function claim(string $worker, int $limit): Collection
    {
        if ($limit < 1) {
            return new Collection;
        }

        return $this->connection()->transaction(function () use ($worker, $limit): Collection {
            $query = Conversion::query()->pending()->orderBy('id')->limit($limit);

            // SKIP LOCKED steps over the rows another worker's transaction is already holding instead
            // of queueing behind them, so several workers claim at the same moment and get different
            // work. sqlite (the tests) has no row locks at all - a write transaction locks the whole
            // file - and its grammar compiles no lock clause either.
            if (in_array($this->connection()->getDriverName(), self::SKIP_LOCKED_DRIVERS, true)) {
                $query->lock('for update skip locked');
            }

            /** @var list<int> $ids */
            $ids = $query->pluck('id')->all();

            if ($ids === []) {
                return new Collection;
            }

            $now = now();

            // "and it is still pending" is what actually makes the claim exclusive, and it is
            // deliberately not left to the lock clause above: on a driver that never got one, two
            // workers read the same ids, and the loser's UPDATE - which re-reads the rows it locks -
            // then matches nothing instead of overwriting the winner's claim. Without this predicate
            // both workers would convert the same content, which is the fault the old pipeline had.
            $claimed = Conversion::query()->whereIn('id', $ids)->pending()->update([
                'status' => ConversionStatus::Claimed->value,
                'worker' => $worker,
                'claimed_at' => $now,
                'heartbeat_at' => $now,
                'updated_at' => $now,
            ]);

            if ($claimed === 0) {
                return new Collection;
            }

            // Only the rows this call took. A worker that lost the race for some of them gets the rest
            // rather than a collection that includes somebody else's work.
            return Conversion::query()
                ->whereIn('id', $ids)
                ->claimed()
                ->where('worker', $worker)
                ->where('claimed_at', $now)
                ->orderBy('id')
                ->get();
        });
    }

    /**
     * "Still working on it." A conversion of 500 pages can run for minutes; without this the
     * reconciler could not tell a slow content from a dead worker.
     *
     * False means the worker no longer holds the content - the reconciler has already deleted this
     * attempt's page rows and queued the content again - and the caller must stop. A plain save() here
     * would put the heartbeat back on a row somebody else now owns and leave two workers writing pages
     * for one content.
     */
    public function heartbeat(Conversion $conversion): bool
    {
        $now = now();

        return $this->writeWhileHeld($conversion, ['heartbeat_at' => $now, 'updated_at' => $now]);
    }

    /**
     * Records the finished conversion, unless the worker has meanwhile lost the content: a content the
     * reconciler took back has had this attempt's page rows deleted, so marking it done would leave a
     * content the archive believes is converted and has no pages.
     */
    public function succeed(Conversion $conversion, int $pages): bool
    {
        $now = now();

        $attributes = [
            'status' => ConversionStatus::Done->value,
            'pages' => $pages,
            'failure_stage' => null,
            'failure_reason' => null,
            'finished_at' => $now,
            'heartbeat_at' => $now,
            'updated_at' => $now,
        ];

        return $this->writeWhileHeld($conversion, $attributes);
    }

    /**
     * Records a failure and decides whether the content goes back in the queue. The stage and the
     * reason are kept either way - on a retry as well - because they are what a person looks at when
     * the same content keeps coming back.
     *
     * False means the failure was not recorded because the worker no longer holds the content. It is
     * refused rather than written for the same reason heartbeat() is: the content may already be in
     * somebody else's hands, and pulling it back to pending would hand it out twice.
     */
    public function fail(Conversion $conversion, Stage $stage, string $reason, bool $retryable): bool
    {
        return $this->connection()->transaction(function () use ($conversion, $stage, $reason, $retryable): bool {
            /** @var Conversion|null $held */
            $held = $this->whileHeldBy($conversion)->lockForUpdate()->first();

            if ($held === null) {
                return false;
            }

            // Counted from the row under the lock, never from the model in hand: the reconciler counts
            // an attempt on exactly these rows and $conversion may have been loaded before it did.
            // attempts is the whole retry budget, and a lost increment is a content retried forever.
            $attempts = $held->attempts + 1;
            $again = $retryable && $attempts < (int) config('converter.failure.max_attempts');

            $now = now();

            $attributes = [
                'attempts' => $attempts,
                'failure_stage' => $stage->value,
                'failure_reason' => $reason,
                'status' => ($again ? ConversionStatus::Pending : ConversionStatus::Failed)->value,
                'worker' => $again ? null : $held->worker,
                'claimed_at' => $again ? null : $held->claimed_at,
                'heartbeat_at' => null,
                'finished_at' => $again ? null : $now,
                'updated_at' => $now,
            ];

            Conversion::query()->whereKey($held->getKey())->update($attributes);

            $this->syncInMemory($conversion, $attributes);

            return true;
        });
    }

    /**
     * Takes a stale claim over from the worker that abandoned it, so that exactly one reconciler
     * cleans it up. False means somebody else got there first and the caller must leave the
     * conversion alone: its page rows may already belong to a new attempt, and deleting those is the
     * one mistake this ledger exists to prevent.
     *
     * heartbeat_at is deliberately left as it is. It - or claimed_at - is what makes the row stale, so
     * a reconciler that dies halfway through leaves the conversion reclaimable by the next run.
     */
    public function takeOver(Conversion $conversion, string $reclaimer): bool
    {
        return $this->writeWhileHeld($conversion, ['worker' => $reclaimer, 'updated_at' => now()]);
    }

    /**
     * Notes that a page row exists in the archive. Called with the MVDContent ID the moment the
     * archive returns it, before the image is uploaded, so a crash between the two leaves the row
     * findable instead of orphaned.
     */
    public function recordPage(
        Conversion $conversion,
        int $seq,
        ?string $mvdId = null,
        ?string $remotePath = null,
        ?int $bytes = null,
    ): ConversionPage {
        $attributes = array_filter(
            ['mvd_id' => $mvdId, 'remote_path' => $remotePath, 'bytes' => $bytes],
            fn (int|string|null $value): bool => $value !== null,
        );

        /** @var ConversionPage|null $page */
        $page = $conversion->pages()->where('seq', $seq)->first();

        if ($page === null) {
            /** @var ConversionPage $created */
            $created = $conversion->pages()->create($attributes + ['seq' => $seq]);

            return $created;
        }

        // The ledger already names a different MVDContent row for this page, which means a previous
        // attempt's rows were never cleaned up. Overwriting the id would drop the only record of a row
        // that is still standing in a 140 million row table, and the content would end up in the
        // viewer with that page twice. Refused loudly: the retry has to go through the reconciler's
        // reclaim(), which deletes the previous attempt before a new one records anything.
        if ($mvdId !== null && $page->mvd_id !== null && $page->mvd_id !== $mvdId) {
            throw new RuntimeException(sprintf(
                'Page %d of content %s is already recorded as MVDContent %s; the previous attempt was not cleaned up, so %s cannot replace it.',
                $seq,
                $conversion->content_id,
                $page->mvd_id,
                $mvdId,
            ));
        }

        $page->forceFill($attributes)->save();

        return $page;
    }

    /**
     * The image is on the FTP site. Only a page marked here is worth deleting from FTP during a
     * cleanup; anything else never got that far.
     */
    public function markPageUploaded(ConversionPage $page, ?string $remotePath = null, ?int $bytes = null): void
    {
        $page->forceFill(array_filter([
            'remote_path' => $remotePath,
            'bytes' => $bytes,
        ], fn (int|string|null $value): bool => $value !== null) + ['uploaded' => true])->save();
    }

    /**
     * Conversions whose worker stopped reporting. They are the reconciler's input, and $limit keeps
     * one run bounded: a machine that lost every worker at once leaves as many of these as it had
     * contents in flight, and each one costs the archive a delete and a release.
     *
     * @return Collection<int, Conversion>
     */
    public function stale(?int $limit = null): Collection
    {
        $query = Conversion::query()->stale()->orderBy('id');

        if ($limit !== null) {
            $query->limit($limit);
        }

        return $query->get();
    }

    /**
     * The row, but only while the worker that was handed it still holds it.
     *
     * @return Builder<Conversion>
     */
    /**
     * Writes to the conversion, but only while the worker in hand still holds it, and says whether
     * it did.
     *
     * Whether the row is still ours is asked with a SELECT, not read from the number of rows the
     * UPDATE reports. MySQL reports the rows it *changed*: a heartbeat written twice inside one
     * second writes the timestamp the row already holds, so it changed nothing, the count came back 0
     * and read as "another worker has this content" - and the worker abandoned a conversion it was
     * holding perfectly well. It happens on MySQL only, and sqlite (what the tests run on) counts
     * matched rows, so nothing here could see it. The two statements share a transaction and the row
     * is locked, so the answer cannot change between them.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function writeWhileHeld(Conversion $conversion, array $attributes): bool
    {
        return $this->connection()->transaction(function () use ($conversion, $attributes): bool {
            if (! $this->whileHeldBy($conversion)->lockForUpdate()->exists()) {
                return false;
            }

            $this->whileHeldBy($conversion)->update($attributes);
            $this->syncInMemory($conversion, $attributes);

            return true;
        });
    }

    private function whileHeldBy(Conversion $conversion): Builder
    {
        return Conversion::query()
            ->whereKey($conversion->getKey())
            ->claimed()
            ->where('worker', $conversion->worker);
    }

    /**
     * Brings the model in the caller's hand up to date with the row that was just written, without
     * leaving it dirty: the next save() from the pipeline must not write these columns again.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function syncInMemory(Conversion $conversion, array $attributes): void
    {
        $conversion->forceFill($attributes);

        foreach (array_keys($attributes) as $column) {
            $conversion->syncOriginalAttribute($column);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $rows
     */
    private function insertIgnoringExisting(array $rows): int
    {
        // The unique index on content_id is what does the de-duplicating, in the database, in one
        // statement - not a SELECT of half a million ids into PHP first.
        return Conversion::query()->insertOrIgnore(array_values($rows));
    }

    private function connection(): Connection
    {
        /** @var Connection $connection */
        $connection = Conversion::query()->getConnection();

        return $connection;
    }
}
