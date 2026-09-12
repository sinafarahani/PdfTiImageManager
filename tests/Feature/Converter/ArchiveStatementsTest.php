<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SqlServerArchive;
use App\Actions\Converter\Archive\StoreMode;
use Carbon\CarbonImmutable;
use PDOException;
use RuntimeException;
use Tests\TestCase;

/**
 * The golden statements.
 *
 * Every expected SQL string below is written out again by hand instead of being read from the class,
 * on purpose: these assertions exist so that an edit to SqlServerArchive cannot quietly change what
 * is written to a production archive of 93 million contents. If one of them fails, the question to
 * answer is not "how do I make the test pass" but "did I mean to change the archive".
 */
class ArchiveStatementsTest extends TestCase
{
    public function test_discover_filters_on_all_five_conditions(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->discover(null, 5000);

        $expected = <<<'SQL'
            SELECT DISTINCT TOP (?) g.ID, g.ProcessDate, g.ProfileID
            FROM GeneralContent g
            INNER JOIN MVDContent cf ON g.ID = cf.ContentID
            WHERE cf.Format = ?
              AND cf.Deleted = 0
              AND (g.FS3dIndexItemCountThresholdStatus = 0 OR g.FS3dIndexItemCountThresholdStatus IS NULL)
              AND g.Reserved = ?
              AND g.RenderMediaId <> 1
            ORDER BY g.ProcessDate, g.ID
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame('select', $statement['method']);
        $this->assertSame($expected, $statement['sql']);
        $this->assertSame([5000, 'application/pdf', '00000000-0000-0000-0000-000000000000'], $statement['bindings']);
    }

    public function test_discover_scans_forward_from_the_watermark(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->discover(CarbonImmutable::parse('2025-01-07 16:39:53'), 10);

        $expected = <<<'SQL'
            SELECT DISTINCT TOP (?) g.ID, g.ProcessDate, g.ProfileID
            FROM GeneralContent g
            INNER JOIN MVDContent cf ON g.ID = cf.ContentID
            WHERE cf.Format = ?
              AND cf.Deleted = 0
              AND (g.FS3dIndexItemCountThresholdStatus = 0 OR g.FS3dIndexItemCountThresholdStatus IS NULL)
              AND g.Reserved = ?
              AND g.RenderMediaId <> 1
              AND g.ProcessDate > ?
            ORDER BY g.ProcessDate, g.ID
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(
            [10, 'application/pdf', '00000000-0000-0000-0000-000000000000', '2025-01-07 16:39:53'],
            $statement['bindings'],
        );
    }

    public function test_discover_maps_the_rows_it_reads(): void
    {
        $connection = (new RecordingConnection)->willReturn([
            ['ID' => 'A1B2C3D4-0000-0000-0000-000000000001', 'ProcessDate' => '2025-01-07 16:39:53.000', 'ProfileID' => '7'],
            ['ID' => 'A1B2C3D4-0000-0000-0000-000000000002', 'ProcessDate' => null, 'ProfileID' => null],
        ]);

        $found = (new SqlServerArchive($connection, 'on'))->discover(null, 2);

        $this->assertSame('A1B2C3D4-0000-0000-0000-000000000001', $found[0]->contentId);
        $this->assertSame(7, $found[0]->profileId);
        $this->assertSame('2025-01-07 16:39:53', $found[0]->processDate?->format('Y-m-d H:i:s'));
        $this->assertNull($found[1]->profileId);
        $this->assertNull($found[1]->processDate);
    }

    public function test_seed_from_legacy_queue_pages_through_pdfconvert(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->seedFromLegacyQueue(500, 1000);

        $expected = <<<'SQL'
            SELECT ID, ProfileID, ProcessDate
            FROM PdfConvert
            WHERE state = 0
            ORDER BY ProcessDate, ID
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame([1000, 500], $statement['bindings']);
    }

    public function test_reserve_only_wins_against_the_free_marker(): void
    {
        $connection = new RecordingConnection(affected: 1);

        $this->assertTrue((new SqlServerArchive($connection, 'on'))->reserve('C0000000-0000-0000-0000-000000000001', 'worker-3'));

        $statement = $connection->onlyStatement();

        $this->assertSame('update', $statement['method']);
        $this->assertSame('UPDATE GeneralContent SET Reserved = ? WHERE ID = ? AND Reserved = ?', $statement['sql']);
        $this->assertSame(
            ['worker-3', 'C0000000-0000-0000-0000-000000000001', '00000000-0000-0000-0000-000000000000'],
            $statement['bindings'],
        );
    }

    public function test_reserve_reports_a_content_somebody_else_holds(): void
    {
        $connection = new RecordingConnection(affected: 0);

        $this->assertFalse((new SqlServerArchive($connection, 'on'))->reserve('C0000000-0000-0000-0000-000000000001', 'worker-3'));
    }

    public function test_release_puts_the_free_marker_back_without_erasing_a_verdict(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->release('C0000000-0000-0000-0000-000000000001');

        // Reserved carries the archive's converted and failed markers as well as the reservation, and
        // the viewer reads them. A release must not be able to clear one: releasing after a
        // markConverted() - the shape a finally block gives you - would otherwise erase
        // DD18D1A0-C693-4379-B350-8F37E7612998 from every content this pipeline converts.
        $expected = <<<'SQL'
            UPDATE GeneralContent
            SET Reserved = ?
            WHERE ID = ?
              AND Reserved <> ?
              AND Reserved <> ?
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(
            [
                '00000000-0000-0000-0000-000000000000',
                'C0000000-0000-0000-0000-000000000001',
                'DD18D1A0-C693-4379-B350-8F37E7612998',
                '11111111-1111-1111-1111-111111111111',
            ],
            $statement['bindings'],
        );
    }

    public function test_source_files_skip_deleted_rows_and_arrive_unpadded(): void
    {
        $connection = (new RecordingConnection)->willReturn([[
            'ID' => 'F0000000-0000-0000-0000-000000000001',
            'SeqPageNo' => '0',
            'PageNo' => str_pad('book.pdf', 200),
            'CreateDateTime' => '2025-01-07 16:39:53',
            'Format' => 'Application/pdf',
            'FtpSiteID' => '1',
        ]]);

        $files = (new SqlServerArchive($connection, 'on'))->sourceFilesFor('C0000000-0000-0000-0000-000000000001');

        $expected = <<<'SQL'
            SELECT ID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE ContentID = ?
              AND Deleted = 0
            ORDER BY SeqPageNo
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(['C0000000-0000-0000-0000-000000000001'], $statement['bindings']);

        $this->assertSame('book.pdf', $files[0]->pageNo);
        $this->assertSame(0, $files[0]->seqPageNo);
        $this->assertSame(1, $files[0]->ftpSiteId);
        $this->assertTrue($files[0]->isPdf());
    }

    public function test_image_pages_match_every_image_format(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->imagePagesFor('C0000000-0000-0000-0000-000000000001');

        $expected = <<<'SQL'
            SELECT ID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE ContentID = ?
              AND Format LIKE ?
            ORDER BY SeqPageNo
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(['C0000000-0000-0000-0000-000000000001', 'image/%'], $statement['bindings']);
    }

    public function test_profile_id_reads_one_row(): void
    {
        $connection = (new RecordingConnection)->willReturn([['ProfileID' => '12']]);

        $this->assertSame(12, (new SqlServerArchive($connection, 'on'))->profileIdFor('C0000000-0000-0000-0000-000000000001'));

        $statement = $connection->onlyStatement();

        $this->assertSame('SELECT TOP (1) ProfileID FROM GeneralContent WHERE ID = ?', $statement['sql']);
        $this->assertSame(['C0000000-0000-0000-0000-000000000001'], $statement['bindings']);
    }

    public function test_store_mode_trims_the_padded_nchar_value(): void
    {
        $connection = (new RecordingConnection)->willReturn([['StoreMode' => 'fs  ']]);

        $this->assertSame(StoreMode::FileSystem, (new SqlServerArchive($connection, 'on'))->storeModeFor(3));

        $statement = $connection->onlyStatement();

        $this->assertSame('SELECT TOP (1) StoreMode FROM DMDProfile WHERE ID = ?', $statement['sql']);
        $this->assertSame([3], $statement['bindings']);
    }

    public function test_an_unknown_profile_keeps_its_image_in_the_database(): void
    {
        $archive = new SqlServerArchive(new RecordingConnection, 'on');

        $this->assertSame(StoreMode::Database, $archive->storeModeFor(999));
    }

    public function test_current_file_site_reads_the_live_row_and_trims_the_folder(): void
    {
        $connection = (new RecordingConnection)->willReturn([[
            'ID' => '1',
            'FtpServer' => 'Archive-app',
            'FtpPort' => '21',
            'FtpServerFolder' => str_pad('DOI', 60),
            'FtpUsername' => 'archive',
            'FtpPassword' => 'secret',
        ]]);

        $site = (new SqlServerArchive($connection, 'on'))->currentFileSite();

        $expected = <<<'SQL'
            SELECT TOP (1) ID, FtpServer, FtpPort, FtpServerFolder, FtpUsername, FtpPassword
            FROM FtpSites
            WHERE FtpType = ?
              AND CurrentFtp = 1
            ORDER BY ID
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(['file'], $statement['bindings']);

        $this->assertSame(1, $site->id);
        $this->assertSame(21, $site->port);
        $this->assertSame('DOI', $site->folder);
        $this->assertSame('secret', $site->password());
    }

    public function test_current_file_site_refuses_to_guess(): void
    {
        $this->expectExceptionMessage('The archive has no current FTP site of type "file".');

        (new SqlServerArchive(new RecordingConnection, 'on'))->currentFileSite();
    }

    public function test_insert_page_takes_the_id_from_the_database(): void
    {
        $connection = (new RecordingConnection)->willReturn([['ID' => '8DB1E6AE-0000-0000-0000-000000000009']]);

        $mvdId = (new SqlServerArchive($connection, 'on'))->insertPage(new PageInsert(
            contentId: 'C0000000-0000-0000-0000-000000000001',
            seqPageNo: 3,
            createDateTime: '2025-01-07 16:39:53',
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: "\xFF\xD8\xFFthumb\x00",
        ));

        $this->assertSame('8DB1E6AE-0000-0000-0000-000000000009', $mvdId);

        $expected = <<<'SQL'
            INSERT INTO MVDContent (ContentID, PageNo, SeqPageNo, Format, CreateDateTime, FtpSiteID, Deleted)
            OUTPUT inserted.ID
            VALUES (?, ?, ?, ?, ?, ?, 0)
            SQL;

        [$page, $thumb] = $connection->statements();

        $this->assertSame($expected, $page['sql']);
        $this->assertStringNotContainsString('(ID,', $page['sql']);
        $this->assertStringNotContainsString('newid', strtolower($page['sql']));
        $this->assertSame(
            ['C0000000-0000-0000-0000-000000000001', '3', 3, 'Image/jpg', '2025-01-07 16:39:53', 1],
            $page['bindings'],
        );

        $this->assertSame('INSERT INTO ThumbLayer (RefPageID, thumb) VALUES (?, ?)', $thumb['sql']);
        $this->assertSame(['8DB1E6AE-0000-0000-0000-000000000009', "\xFF\xD8\xFFthumb\x00"], $thumb['bindings']);

        // One transaction around all three rows: a page row without its thumbnail is a hole in the viewer.
        $this->assertSame(['begin', 'select', 'insert', 'commit'], $connection->methods());
    }

    public function test_insert_page_writes_the_image_layer_only_for_a_database_profile(): void
    {
        $connection = (new RecordingConnection)->willReturn([['ID' => '8DB1E6AE-0000-0000-0000-000000000009']]);

        (new SqlServerArchive($connection, 'on'))->insertPage(new PageInsert(
            contentId: 'C0000000-0000-0000-0000-000000000001',
            seqPageNo: 1,
            createDateTime: '2025-01-07 16:39:53',
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: 'thumb-bytes',
            image: "\xFF\xD8\xFFpage",
        ));

        [, , $image] = $connection->statements();

        $this->assertSame('INSERT INTO ImageLayer (RefPageID, Pic, Applied) VALUES (?, ?, 0)', $image['sql']);
        $this->assertSame(['8DB1E6AE-0000-0000-0000-000000000009', "\xFF\xD8\xFFpage"], $image['bindings']);
        $this->assertSame(['begin', 'select', 'insert', 'insert', 'commit'], $connection->methods());
    }

    public function test_insert_page_refuses_a_page_without_a_thumbnail(): void
    {
        $connection = (new RecordingConnection)->willReturn([['ID' => '8DB1E6AE-0000-0000-0000-000000000009']]);

        $page = new PageInsert(
            contentId: 'C0000000-0000-0000-0000-000000000001',
            seqPageNo: 4,
            createDateTime: '2025-01-07 16:39:53',
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: '',
        );

        try {
            (new SqlServerArchive($connection, 'on'))->insertPage($page);
            $this->fail('A page with no thumbnail was written.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('has no thumbnail', $exception->getMessage());
        }

        // Every one of the archive's pages has a ThumbLayer row; an MVDContent row whose thumbnail is
        // empty is the same hole in the viewer, so nothing at all may be written.
        $this->assertSame([], $connection->calls);
    }

    public function test_soft_delete_source_flags_the_pdf_row(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->softDeleteSource('F0000000-0000-0000-0000-000000000001');

        $statement = $connection->onlyStatement();

        $this->assertSame('UPDATE MVDContent SET Deleted = 1 WHERE ID = ?', $statement['sql']);
        $this->assertSame(['F0000000-0000-0000-0000-000000000001'], $statement['bindings']);
    }

    public function test_delete_pages_binds_every_id(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->deletePages([
            'F0000000-0000-0000-0000-000000000001',
            'F0000000-0000-0000-0000-000000000002',
        ]);

        $statement = $connection->onlyStatement();

        $this->assertSame('delete', $statement['method']);
        $this->assertSame('DELETE FROM MVDContent WHERE Format LIKE ? AND ID IN (?, ?)', $statement['sql']);
        $this->assertSame(
            ['image/%', 'F0000000-0000-0000-0000-000000000001', 'F0000000-0000-0000-0000-000000000002'],
            $statement['bindings'],
        );
    }

    public function test_delete_pages_can_never_remove_a_source_pdf_row(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->deletePages(['F0000000-0000-0000-0000-000000000001']);

        // The undo runs against a 140 million row table. One id that is not a page - a source row that
        // came back from sourceFilesFor(), say - would delete the PDF's own MVDContent row and cascade
        // its ThumbLayer away, leaving the only copy of the original on FTP with nothing pointing at it.
        $this->assertStringContainsString('Format LIKE ?', $connection->onlyStatement()['sql']);
    }

    public function test_delete_pages_runs_nothing_for_an_empty_undo(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->deletePages([]);

        $this->assertSame([], $connection->calls);
    }

    public function test_delete_pages_stays_under_the_parameter_limit(): void
    {
        $connection = new RecordingConnection;

        $ids = array_map(fn (int $n): string => sprintf('F0000000-0000-0000-0000-%012d', $n), range(1, 1200));

        (new SqlServerArchive($connection, 'on'))->deletePages($ids);

        $statements = $connection->statements();

        $this->assertCount(3, $statements);
        // 500 ids plus the format guard, so every statement stays well inside SQL Server's 2100.
        $this->assertCount(501, $statements[0]['bindings']);
        $this->assertCount(501, $statements[1]['bindings']);
        $this->assertCount(201, $statements[2]['bindings']);
    }

    public function test_mark_converted_writes_all_five_columns(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->markConverted('C0000000-0000-0000-0000-000000000001');

        $expected = <<<'SQL'
            UPDATE GeneralContent
            SET FS3dIndexItemCountThresholdStatus = 1, Reserved = ?, RenderMediaId = 1, HasView = 1, ModifyDate = GETDATE()
            WHERE ID = ?
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(
            ['DD18D1A0-C693-4379-B350-8F37E7612998', 'C0000000-0000-0000-0000-000000000001'],
            $statement['bindings'],
        );

        // The old procedure also set PdfConvert.state; the queue lives in MySQL now and that heap is
        // deliberately left alone.
        $this->assertStringNotContainsString('PdfConvert', $statement['sql']);
    }

    public function test_mark_failed_keeps_the_archives_failure_marker(): void
    {
        $connection = new RecordingConnection;

        (new SqlServerArchive($connection, 'on'))->markFailed('C0000000-0000-0000-0000-000000000001');

        $expected = <<<'SQL'
            UPDATE GeneralContent
            SET FS3dIndexItemCountThresholdStatus = 1, Reserved = ?, ModifyDate = GETDATE()
            WHERE ID = ?
            SQL;

        $statement = $connection->onlyStatement();

        $this->assertSame($expected, $statement['sql']);
        $this->assertSame(
            ['11111111-1111-1111-1111-111111111111', 'C0000000-0000-0000-0000-000000000001'],
            $statement['bindings'],
        );
    }

    public function test_a_deadlock_victim_tries_the_claim_again(): void
    {
        $connection = new class extends RecordingConnection
        {
            public int $deadlocks = 2;

            public function update($query, $bindings = []): int
            {
                if ($this->deadlocks-- > 0) {
                    throw self::deadlock();
                }

                return parent::update($query, $bindings);
            }

            public static function deadlock(): PDOException
            {
                $exception = new PDOException('Transaction was deadlocked on lock resources.', 0);
                $exception->errorInfo = ['40001', 1205, 'Transaction was deadlocked on lock resources.'];

                return $exception;
            }
        };

        // Two workers claiming neighbouring rows of GeneralContent deadlock, and one of them is chosen
        // as the victim. Losing the content over it would mean it is never converted.
        $archive = new SqlServerArchive($connection, 'on', deadlockAttempts: 3, deadlockBackoffMicroseconds: 0);

        $this->assertTrue($archive->reserve('C0000000-0000-0000-0000-000000000001', 'worker-3'));
        $this->assertCount(1, $connection->statements());
    }

    public function test_an_error_that_is_not_a_deadlock_is_not_retried(): void
    {
        $connection = new class extends RecordingConnection
        {
            public int $attempts = 0;

            public function update($query, $bindings = []): int
            {
                $this->attempts++;

                $exception = new PDOException('Invalid column name.', 0);
                $exception->errorInfo = ['42S22', 207, 'Invalid column name.'];

                throw $exception;
            }
        };

        $archive = new SqlServerArchive($connection, 'on', deadlockAttempts: 3, deadlockBackoffMicroseconds: 0);

        try {
            $archive->reserve('C0000000-0000-0000-0000-000000000001', 'worker-3');
            $this->fail('A broken statement was swallowed.');
        } catch (PDOException) {
            // Anything but a deadlock is a real problem, and retrying it only hides it.
            $this->assertSame(1, $connection->attempts);
        }
    }

    public function test_the_reserved_markers_match_the_configured_ones(): void
    {
        $this->assertSame(config('converter.archive.reserved.free'), SqlServerArchive::RESERVED_FREE);
        $this->assertSame(config('converter.archive.reserved.converted'), SqlServerArchive::RESERVED_CONVERTED);
        $this->assertSame(config('converter.archive.reserved.failed'), SqlServerArchive::RESERVED_FAILED);
    }
}
