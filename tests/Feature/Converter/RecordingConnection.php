<?php

namespace Tests\Feature\Converter;

use Closure;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * A database connection that runs nothing and remembers everything: which method was called, the SQL
 * text, and the bindings in the order they were given.
 *
 * The archive is a live 93 million row SQL Server that the test suite has no business reaching, and a
 * SQLite stand-in would reject TOP (?), OUTPUT inserted.ID and OFFSET ... FETCH NEXT before any
 * listener saw them. So the statements are asserted here, against exactly what SqlServerArchive
 * produced.
 */
class RecordingConnection implements ConnectionInterface
{
    /** @var list<array{method: string, sql: string, bindings: array<int, mixed>}> */
    public array $calls = [];

    /** @var list<list<object>> answers handed to select()/selectOne(), oldest first */
    private array $results = [];

    public function __construct(public int $affected = 1) {}

    /**
     * Queues one answer for the next read. Rows are given as column maps.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    public function willReturn(array $rows): self
    {
        $this->results[] = array_map(fn (array $row): object => (object) $row, $rows);

        return $this;
    }

    /**
     * @return list<array{method: string, sql: string, bindings: array<int, mixed>}>
     */
    public function statements(): array
    {
        return array_values(array_filter($this->calls, fn (array $call): bool => $call['sql'] !== ''));
    }

    /**
     * @return array{method: string, sql: string, bindings: array<int, mixed>}
     */
    public function onlyStatement(): array
    {
        $statements = $this->statements();

        if (count($statements) !== 1) {
            throw new RuntimeException('Expected exactly one statement, recorded '.count($statements).'.');
        }

        return $statements[0];
    }

    /**
     * @return list<string>
     */
    public function methods(): array
    {
        return array_map(fn (array $call): string => $call['method'], $this->calls);
    }

    public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        $this->record('select', $query, $bindings);

        return array_shift($this->results) ?? [];
    }

    public function selectOne($query, $bindings = [], $useReadPdo = true): ?object
    {
        return $this->select($query, $bindings, $useReadPdo)[0] ?? null;
    }

    public function scalar($query, $bindings = [], $useReadPdo = true): mixed
    {
        $row = (array) $this->selectOne($query, $bindings, $useReadPdo);

        return reset($row) ?: null;
    }

    public function cursor($query, $bindings = [], $useReadPdo = true, array $fetchUsing = []): array
    {
        return $this->select($query, $bindings, $useReadPdo, $fetchUsing);
    }

    public function insert($query, $bindings = []): bool
    {
        $this->record('insert', $query, $bindings);

        return true;
    }

    public function update($query, $bindings = []): int
    {
        $this->record('update', $query, $bindings);

        return $this->affected;
    }

    public function delete($query, $bindings = []): int
    {
        $this->record('delete', $query, $bindings);

        return $this->affected;
    }

    public function statement($query, $bindings = []): bool
    {
        $this->record('statement', $query, $bindings);

        return true;
    }

    public function affectingStatement($query, $bindings = []): int
    {
        $this->record('affectingStatement', $query, $bindings);

        return $this->affected;
    }

    public function unprepared($query): bool
    {
        $this->record('unprepared', $query, []);

        return true;
    }

    public function transaction(Closure $callback, $attempts = 1): mixed
    {
        $this->calls[] = ['method' => 'begin', 'sql' => '', 'bindings' => []];

        $result = $callback($this);

        $this->calls[] = ['method' => 'commit', 'sql' => '', 'bindings' => []];

        return $result;
    }

    public function beginTransaction(): void
    {
        $this->calls[] = ['method' => 'begin', 'sql' => '', 'bindings' => []];
    }

    public function commit(): void
    {
        $this->calls[] = ['method' => 'commit', 'sql' => '', 'bindings' => []];
    }

    public function rollBack(): void
    {
        $this->calls[] = ['method' => 'rollBack', 'sql' => '', 'bindings' => []];
    }

    public function transactionLevel(): int
    {
        return 0;
    }

    public function pretend(Closure $callback): array
    {
        return [];
    }

    public function table($table, $as = null): never
    {
        throw new RuntimeException('SqlServerArchive must write its SQL by hand, not build it.');
    }

    public function raw($value): never
    {
        throw new RuntimeException('SqlServerArchive must write its SQL by hand, not build it.');
    }

    public function prepareBindings(array $bindings): array
    {
        return $bindings;
    }

    public function getDatabaseName(): string
    {
        return 'archive';
    }

    /**
     * @param  array<int, mixed>  $bindings
     */
    private function record(string $method, string $query, array $bindings): void
    {
        $this->calls[] = ['method' => $method, 'sql' => $query, 'bindings' => $bindings];
    }
}
