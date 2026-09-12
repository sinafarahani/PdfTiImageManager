<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SqlServerArchive;
use RuntimeException;
use Tests\TestCase;

/**
 * The dry run. With converter.archive.write_mode set to "off" the whole pipeline can be pointed at
 * the production archive to see what it would do, and it must be unable to change a row: not a
 * reservation, not a page, not a marker. The gate has to stop the statement before the driver sees
 * it, which is why every test here also asserts that nothing at all reached the connection.
 */
class ArchiveWriteModeTest extends TestCase
{
    public function test_reserve_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->reserve('C1', 'worker-1'), 'reserve');
    }

    public function test_release_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->release('C1'), 'release');
    }

    public function test_insert_page_is_blocked(): void
    {
        $page = new PageInsert('C1', 1, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-bytes');

        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->insertPage($page), 'insertPage');
    }

    public function test_soft_delete_source_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->softDeleteSource('F1'), 'softDeleteSource');
    }

    public function test_delete_pages_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->deletePages(['F1']), 'deletePages');
    }

    public function test_mark_converted_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->markConverted('C1'), 'markConverted');
    }

    public function test_mark_failed_is_blocked(): void
    {
        $this->assertBlocked(fn (SqlServerArchive $archive) => $archive->markFailed('C1'), 'markFailed');
    }

    public function test_reads_still_run_during_a_dry_run(): void
    {
        $connection = (new RecordingConnection)->willReturn([
            ['ID' => 'C0000000-0000-0000-0000-000000000001', 'ProcessDate' => '2025-01-07 16:39:53', 'ProfileID' => '1'],
        ]);

        $found = (new SqlServerArchive($connection, 'off'))->discover(null, 10);

        $this->assertCount(1, $found);
        $this->assertCount(1, $connection->statements());
    }

    /**
     * The gate is fail-closed on purpose. CONVERTER_WRITE_MODE is an env var an operator edits under
     * pressure, and every one of these values is a plausible way to write "not on": if any of them let
     * a write through, a blank line in .env would be all that stood between a dry run and 93 million
     * live rows.
     */
    public function test_only_an_explicit_on_opens_the_gate(): void
    {
        foreach (['', ' ', 'off', 'OFF', 'false', '0', 'no', 'disabled', 'dry-run', 'read-only'] as $mode) {
            $connection = new RecordingConnection;

            try {
                (new SqlServerArchive($connection, $mode))->markConverted('C1');
                $this->fail("write_mode \"{$mode}\" let a write through.");
            } catch (RuntimeException) {
                $this->assertSame([], $connection->calls, "write_mode \"{$mode}\" reached the connection.");
            }
        }

        foreach (['on', 'ON', ' on ', 'true', '1', 'yes', 'enabled'] as $mode) {
            $connection = new RecordingConnection;

            (new SqlServerArchive($connection, $mode))->markConverted('C1');

            $this->assertCount(1, $connection->statements(), "write_mode \"{$mode}\" did not write.");
        }
    }

    public function test_a_write_gate_callable_decides_per_call(): void
    {
        $connection = new RecordingConnection;
        $allowed = false;

        $archive = new SqlServerArchive($connection, function () use (&$allowed): bool {
            return $allowed;
        });

        try {
            $archive->markConverted('C1');
            $this->fail('The gate let a write through while it was closed.');
        } catch (RuntimeException) {
            $this->assertSame([], $connection->calls);
        }

        $allowed = true;
        $archive->markConverted('C1');

        $this->assertCount(1, $connection->statements());
    }

    private function assertBlocked(callable $write, string $operation): void
    {
        $connection = new RecordingConnection;
        $archive = new SqlServerArchive($connection, 'off');

        try {
            $write($archive);
            $this->fail("Archive write \"{$operation}\" ran while write_mode was off.");
        } catch (RuntimeException $exception) {
            $this->assertSame(
                "Archive write \"{$operation}\" was blocked: converter.archive.write_mode is not \"on\".",
                $exception->getMessage(),
            );
        }

        $this->assertSame([], $connection->calls, "Archive write \"{$operation}\" reached the connection.");
    }
}
