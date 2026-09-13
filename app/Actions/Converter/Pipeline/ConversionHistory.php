<?php

namespace App\Actions\Converter\Pipeline;

use App\Models\Conversion;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * How much was converted, over time.
 *
 * The counting is done by the database and only the finished columns come back, because "all" over a
 * table of half a million conversions is not something to pull into PHP. It is one grouped read of
 * the [status, finished_at] index.
 */
class ConversionHistory
{
    /**
     * The columns of the chart, with the empty stretches filled in.
     *
     * @return array{
     *     range: HistoryRange,
     *     bucket: HistoryBucket,
     *     points: list<array{key: string, label: string, title: string, total: int, share: float}>,
     *     total: int,
     *     peak: int,
     *     busiest: ?array{title: string, total: int},
     * }
     */
    public function of(HistoryRange $range, ?CarbonImmutable $now = null): array
    {
        $now = $now ?? CarbonImmutable::now();
        $since = $range->since($now);
        $earliest = $since ?? $this->earliestFinish();
        $bucket = $range->bucket($earliest, $now);

        $counted = $this->countByBucket($bucket, $since);

        $points = [];
        $peak = 0;
        $total = 0;

        // Nothing converted at all: no columns rather than a year of zeroes.
        if ($earliest === null) {
            return [
                'range' => $range,
                'bucket' => $bucket,
                'points' => [],
                'total' => 0,
                'peak' => 0,
                'busiest' => null,
            ];
        }

        // Walked forward one column at a time rather than read off the rows, so that an hour in which
        // nothing was converted is drawn as an empty column instead of being left out - a chart that
        // silently closes its gaps says the pipeline never stopped.
        for ($at = $bucket->start($earliest); $at <= $now; $at = $bucket->next($at)) {
            $key = $bucket->keyFor($at);
            $converted = (int) ($counted[$key] ?? 0);

            $points[] = [
                'key' => $key,
                'label' => $bucket->labelFor($at),
                'title' => $bucket->titleFor($at),
                'total' => $converted,
                'share' => 0.0,
            ];

            $peak = max($peak, $converted);
            $total += $converted;
        }

        $busiest = null;

        foreach ($points as $index => $point) {
            // Heights are shares of the tallest column, so a quiet day next to a busy one still shows
            // something rather than nothing: a column with any work at all is never shorter than a
            // line, and only a genuinely empty one is empty.
            $points[$index]['share'] = $peak > 0 && $point['total'] > 0
                ? max(0.02, $point['total'] / $peak)
                : 0.0;

            if ($busiest === null && $point['total'] === $peak && $peak > 0) {
                $busiest = ['title' => $point['title'], 'total' => $point['total']];
            }
        }

        return [
            'range' => $range,
            'bucket' => $bucket,
            'points' => $points,
            'total' => $total,
            'peak' => $peak,
            'busiest' => $busiest,
        ];
    }

    /**
     * Conversions that finished, counted per column, straight from the database.
     *
     * @return array<string, int>
     */
    private function countByBucket(HistoryBucket $bucket, ?CarbonImmutable $since): array
    {
        $query = Conversion::query()
            ->where('status', ConversionStatus::Done)
            ->whereNotNull('finished_at')
            ->selectRaw($this->bucketExpression($bucket).' as bucket, count(*) as total')
            ->groupBy('bucket');

        if ($since !== null) {
            $query->where('finished_at', '>=', $since);
        }

        return $query->pluck('total', 'bucket')
            ->map(fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * The SQL that turns a finished_at into the column it belongs to.
     *
     * The pattern itself is the same for MySQL and SQLite - both spell these fields %Y, %m, %d and
     * %H - so only the function around it changes. It is built from HistoryBucket rather than from
     * anything a request carries, so there is nothing here to inject through.
     */
    private function bucketExpression(HistoryBucket $bucket): string
    {
        $pattern = $bucket->pattern();

        return match ($driver = $this->connection()->getDriverName()) {
            'mysql', 'mariadb' => "date_format(finished_at, '{$pattern}')",
            'sqlite' => "strftime('{$pattern}', finished_at)",
            'pgsql' => "to_char(finished_at, '{$this->postgresPattern($bucket)}')",
            default => throw new RuntimeException("The conversion history cannot be grouped on a {$driver} connection."),
        };
    }

    /**
     * Postgres spells its date fields differently from everybody else.
     */
    private function postgresPattern(HistoryBucket $bucket): string
    {
        return match ($bucket) {
            HistoryBucket::Hour => 'YYYY-MM-DD HH24:00',
            HistoryBucket::Day => 'YYYY-MM-DD',
            HistoryBucket::Month => 'YYYY-MM',
        };
    }

    private function earliestFinish(): ?CarbonImmutable
    {
        $earliest = Conversion::query()
            ->where('status', ConversionStatus::Done)
            ->whereNotNull('finished_at')
            ->min('finished_at');

        return $earliest === null ? null : CarbonImmutable::parse($earliest);
    }

    private function connection(): ConnectionInterface
    {
        return Conversion::query()->getConnection();
    }
}
