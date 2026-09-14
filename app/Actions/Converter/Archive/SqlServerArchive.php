<?php

namespace App\Actions\Converter\Archive;

use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionInterface;
use PDO;
use PDOException;
use Ramsey\Uuid\Uuid;
use RuntimeException;

/**
 * The archive database (SQL Server), spoken to directly.
 *
 * Every statement here replaces one of the archive's stored procedures. They are written out in full,
 * and asserted word for word by ArchiveStatementsTest, because this class is the only thing in the
 * panel that can change a 93 million row production archive: a silent edit to one of these strings
 * would be discovered months later, in the viewer, by a customer.
 *
 * Nothing is interpolated into SQL. The only place a value ever reaches the text is the placeholder
 * list of deletePages(), and that is built from a count, never from an id.
 */
class SqlServerArchive implements ArchiveGateway
{
    /**
     * GeneralContent.Reserved of a content nobody holds. Discovery looks for exactly this value, and
     * reserve() only wins against it.
     */
    public const string RESERVED_FREE = '00000000-0000-0000-0000-000000000000';

    /**
     * The marker spUpdateCommentByContentIDAfterCoordinate wrote for a converted content. The
     * archive's viewer reads it, so it must stay byte-identical, casing included.
     */
    public const string RESERVED_CONVERTED = 'DD18D1A0-C693-4379-B350-8F37E7612998';

    /**
     * The marker the same procedure wrote for a failure. It is kept for compatibility with the old
     * tooling and is only ever written for a final failure, never for an attempt that will be retried.
     */
    public const string RESERVED_FAILED = '11111111-1111-1111-1111-111111111111';

    /**
     * MVDContent.Format of a source PDF. The archive's own rows are mixed case ("Application/pdf");
     * the server's collation is case-insensitive, so the procedure's lower-case literal is kept.
     */
    private const string FORMAT_PDF = 'application/pdf';

    /**
     * Matches every page image format the archive has used ("image/jpg", "image/jpeg", "Image/JPG").
     */
    private const string FORMAT_IMAGE_LIKE = 'image/%';

    /**
     * Matches a source PDF however the archive spelled it ("Application/pdf", "application/PDF").
     */
    private const string FORMAT_PDF_LIKE = '%pdf%';

    /**
     * SQL Server refuses a statement with more than 2100 parameters, so a large undo is split.
     */
    private const int MAX_IDS_PER_DELETE = 500;

    /**
     * The only write_mode values that open the gate. Anything else - "off", a typo, an empty
     * CONVERTER_WRITE_MODE, a well-meant "false" - keeps it shut. A gate that opened for everything
     * it did not recognise would be one blank line in .env away from writing to 93 million live rows.
     *
     * @var list<string>
     */
    private const array WRITE_MODES_ON = ['on', 'true', '1', 'yes', 'enabled'];

    /**
     * @param  ConnectionInterface  $connection  the "archive" connection from config/database.php
     * @param  Closure|string  $writeMode  config('converter.archive.write_mode'), or a callable
     *                                     returning whether writing is allowed right now. Writing is
     *                                     allowed only for one of WRITE_MODES_ON, so a caller that
     *                                     forgets to pass it - or passes an unset env var - gets a dry
     *                                     run, not 93 million rows of production it may write to.
     */
    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly Closure|string $writeMode = 'off',
        private readonly int $deadlockAttempts = 3,
        private readonly int $deadlockBackoffMicroseconds = 200_000,
    ) {}

    /**
     * @return list<DiscoveredContent>
     */
    public function discover(?CarbonImmutable $processedAfter, int $limit): array
    {
        // The watermark is the whole reason this pipeline finds work the old one cannot. The archive's
        // procedure built its candidate list into a snapshot table once and never refreshed it, so
        // roughly 48,500 contents added since that day can never be picked up. Scanning by
        // GeneralContent.ProcessDate from a watermark keeps finding the new ones without walking all
        // 93 million rows on every pass.
        $bindings = [$limit, self::FORMAT_PDF, self::RESERVED_FREE];

        $sql = <<<'SQL'
            SELECT DISTINCT TOP (?) g.ID, g.ProcessDate, g.ProfileID
            FROM GeneralContent g
            INNER JOIN MVDContent cf ON g.ID = cf.ContentID
            WHERE cf.Format = ?
              AND cf.Deleted = 0
              AND (g.FS3dIndexItemCountThresholdStatus = 0 OR g.FS3dIndexItemCountThresholdStatus IS NULL)
              AND g.Reserved = ?
              AND g.RenderMediaId <> 1
            SQL;

        $sql .= $this->exceptSkippedProfiles('g.ProfileID');

        if ($processedAfter !== null) {
            $sql .= "\n  AND g.ProcessDate > ?";
            $bindings[] = $processedAfter->format('Y-m-d H:i:s');
        }

        // Oldest first, and g.ID as the tie-breaker: ProcessDate is a datetime with a 3.33 ms tick and
        // a bulk import gives thousands of contents the same value, so TOP (?) over ProcessDate alone
        // cuts a batch at an arbitrary point inside a tie and the next pass - which asks for
        // ProcessDate strictly greater - would never see the rest of it. The caller still has to rewind
        // its watermark by converter.discovery.overlap_minutes; this only makes the cut deterministic.
        $sql .= "\nORDER BY g.ProcessDate, g.ID";

        return array_map(
            fn (object $row): DiscoveredContent => new DiscoveredContent(
                self::text($row->ID),
                $row->ProfileID === null ? null : (int) $row->ProfileID,
                self::timestamp($row->ProcessDate),
            ),
            $this->connection->select($sql, $bindings),
        );
    }

    /**
     * @return list<DiscoveredContent>
     */
    public function seedFromLegacyQueue(int $limit, int $offset): array
    {
        // PdfConvert is a heap with no index at all, so every read of it is a scan and a sort. It is
        // still far cheaper than the discovery query, and it is read a handful of times in total:
        // once, to fill an empty queue with the work the old pipeline had already found.
        //
        // ID is part of the sort because OFFSET/FETCH only pages consistently over a total order: with
        // ProcessDate alone, two scans of an unindexed heap may break its many ties differently and a
        // row that moves across the page boundary between them is never returned at all.
        $sql = <<<'SQL'
            SELECT ID, ProfileID, ProcessDate
            FROM PdfConvert
            WHERE state = 0
            SQL;

        $sql .= $this->exceptSkippedProfiles('ProfileID');

        $sql .= <<<'SQL'

            ORDER BY ProcessDate, ID
            OFFSET ? ROWS FETCH NEXT ? ROWS ONLY
            SQL;

        return array_map(
            fn (object $row): DiscoveredContent => new DiscoveredContent(
                self::text($row->ID),
                $row->ProfileID === null ? null : (int) $row->ProfileID,
                self::timestamp($row->ProcessDate),
            ),
            $this->connection->select($sql, [$offset, $limit]),
        );
    }

    public function reserve(string $contentId, string $owner): bool
    {
        $sql = 'UPDATE GeneralContent SET Reserved = ? WHERE ID = ? AND Reserved = ?';

        // The condition is the lock: whoever changes the row from free to their own GUID owns the
        // content, and everybody else gets zero rows back. Two workers cannot both win.
        return $this->write(
            'reserve',
            fn (): bool => $this->retryingDeadlocks(
                fn (): bool => $this->connection->update($sql, [$this->reservationFor($owner), $contentId, self::RESERVED_FREE]) === 1,
            ),
        );
    }

    /**
     * The GUID a worker writes into GeneralContent.Reserved to hold a content.
     *
     * Reserved is a uniqueidentifier, so a worker's name cannot go in it - SQL Server refuses the
     * string outright, which is how this was found. A name-based v5 UUID keeps what the name was for:
     * it is the same value every time, so a reserved row can be traced back to the worker holding it,
     * and it cannot collide with the archive's own "converted" and "failed" markers.
     */
    private function reservationFor(string $owner): string
    {
        return (string) Uuid::uuid5(Uuid::NAMESPACE_OID, $owner);
    }

    public function release(string $contentId): void
    {
        // Reserved is a marker as well as a lock: once markConverted() or markFailed() has written
        // theirs the archive's own tooling reads it, and freeing the row would erase it. A release in
        // the shape everyone writes it - a finally after the work - would otherwise wipe
        // DD18D1A0-C693-4379-B350-8F37E7612998 off every content this pipeline ever converts. So the
        // free GUID is only ever written back over a live reservation, never over a verdict.
        $sql = <<<'SQL'
            UPDATE GeneralContent
            SET Reserved = ?
            WHERE ID = ?
              AND Reserved <> ?
              AND Reserved <> ?
            SQL;

        $this->write('release', fn () => $this->connection->update(
            $sql,
            [self::RESERVED_FREE, $contentId, self::RESERVED_CONVERTED, self::RESERVED_FAILED],
        ));
    }

    /**
     * @return list<SourceFile>
     */
    public function sourceFilesFor(string $contentId): array
    {
        $sql = <<<'SQL'
            SELECT ID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE ContentID = ?
              AND Deleted = 0
            ORDER BY SeqPageNo
            SQL;

        return $this->sourceFiles($sql, [$contentId]);
    }

    /**
     * @return list<SourceFile>
     */
    public function imagePagesFor(string $contentId): array
    {
        // Deleted is deliberately not filtered here: these rows are an interrupted attempt's leftovers
        // and one of them may already have been flagged, but its FTP file and its ThumbLayer row are
        // still there and still have to go.
        $sql = <<<'SQL'
            SELECT ID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE ContentID = ?
              AND Format LIKE ?
            ORDER BY SeqPageNo
            SQL;

        return $this->sourceFiles($sql, [$contentId, self::FORMAT_IMAGE_LIKE]);
    }

    public function livePageIdsFor(string $contentId): array
    {
        $sql = <<<'SQL'
            SELECT ID
            FROM MVDContent
            WHERE ContentID = ?
              AND Format LIKE ?
              AND Deleted = 0
            SQL;

        return array_map(
            fn (object $row): string => self::text($row->ID),
            $this->connection->select($sql, [$contentId, self::FORMAT_IMAGE_LIKE]),
        );
    }

    public function sourceRowExists(string $mvdId): bool
    {
        // Deleted is not filtered on purpose: the question is whether the row is there at all.
        return $this->connection->selectOne('SELECT TOP (1) ID FROM MVDContent WHERE ID = ?', [$mvdId]) !== null;
    }

    public function profileIdFor(string $contentId): ?int
    {
        $row = $this->connection->selectOne('SELECT TOP (1) ProfileID FROM GeneralContent WHERE ID = ?', [$contentId]);

        return $row?->ProfileID === null ? null : (int) $row->ProfileID;
    }

    public function storeModeFor(int $profileId): StoreMode
    {
        $row = $this->connection->selectOne('SELECT TOP (1) StoreMode FROM DMDProfile WHERE ID = ?', [$profileId]);

        // StoreMode is nchar(4), so "fs" comes back as "fs  ". StoreMode::fromArchive trims before it
        // compares; a raw comparison here would send every file-system profile down the database path
        // and store a full page image in a table that holds 43,283 rows today.
        return StoreMode::fromArchive($row?->StoreMode);
    }

    public function currentFileSite(): FtpSite
    {
        $sql = <<<'SQL'
            SELECT TOP (1) ID, FtpServer, FtpPort, FtpServerFolder, FtpUsername, FtpPassword
            FROM FtpSites
            WHERE FtpType = ?
              AND CurrentFtp = 1
            ORDER BY ID
            SQL;

        $row = $this->connection->selectOne($sql, ['file']);

        if ($row === null) {
            throw new RuntimeException('The archive has no current FTP site of type "file".');
        }

        // FtpServerFolder is nchar(60) and arrives padded to its full width; joined to a path unchanged
        // it produces "DOI                    /2025/01/07/..." and every upload lands in a folder the
        // viewer will never look in.
        return new FtpSite(
            (int) $row->ID,
            self::text($row->FtpServer),
            (int) $row->FtpPort,
            self::text($row->FtpServerFolder),
            self::text($row->FtpUsername),
            self::text($row->FtpPassword),
        );
    }

    public function insertPage(PageInsert $page): string
    {
        // Every one of the archive's 140,204,325 pages has a ThumbLayer row, and a page whose thumbnail
        // is empty is the same hole in the viewer as a page with no ThumbLayer row at all. Refused here,
        // before the MVDContent row exists, rather than left for somebody to find in the viewer.
        if ($page->thumbnail === '') {
            throw new RuntimeException(
                "Page {$page->seqPageNo} of content {$page->contentId} has no thumbnail; the archive requires one for every page."
            );
        }

        // Same reasoning for a database-stored image: an ImageLayer row of zero bytes is a page the
        // viewer cannot render, and for a "db" profile it is the only copy of the image there is.
        if ($page->image === '') {
            throw new RuntimeException(
                "Page {$page->seqPageNo} of content {$page->contentId} has an empty image; ImageLayer holds the only copy for a \"db\" profile."
            );
        }

        // MVDContent.ID is the clustered key of a 140 million row table and defaults to
        // newsequentialid(); supplying our own GUID would scatter inserts across the whole index. The
        // procedures returned the generated ID through an OUTPUT parameter, OUTPUT inserted.ID returns
        // it as an ordinary result set.
        $insertPage = <<<'SQL'
            INSERT INTO MVDContent (ContentID, PageNo, SeqPageNo, Format, CreateDateTime, FtpSiteID, Deleted)
            OUTPUT inserted.ID
            VALUES (?, ?, ?, ?, ?, ?, 0)
            SQL;

        $bindings = [
            $page->contentId,
            $page->pageNo(),
            $page->seqPageNo,
            $page->format,
            $page->createDateTime,
            $page->ftpSiteId,
        ];

        return $this->write('insertPage', fn (): string => $this->retryingDeadlocks(
            // One transaction for all three rows: a page row without its thumbnail renders as a hole in
            // the viewer, and ThumbLayer/ImageLayer hang off MVDContent.ID by a cascading foreign key.
            fn (): string => $this->connection->transaction(function () use ($insertPage, $bindings, $page): string {
                $rows = $this->connection->select($insertPage, $bindings, false);

                $columns = (array) ($rows[0] ?? null);
                $mvdId = self::text($columns['ID'] ?? $columns['id'] ?? '');

                if ($mvdId === '') {
                    throw new RuntimeException('The archive did not return an MVDContent.ID for the page row.');
                }

                $this->insertBinary('INSERT INTO ThumbLayer (RefPageID, thumb) VALUES (?, ?)', [$mvdId, $page->thumbnail]);

                // Only profiles whose StoreMode is "db" keep the image in the database; for every large
                // profile the image lives on FTP and this row must not exist.
                if ($page->image !== null) {
                    $this->insertBinary('INSERT INTO ImageLayer (RefPageID, Pic, Applied) VALUES (?, ?, 0)', [$mvdId, $page->image]);
                }

                return $mvdId;
            }),
        ));
    }

    public function softDeleteSource(string $mvdId): void
    {
        // The PDF row itself is never removed: the archive hides a converted source by flagging it, and
        // the file stays on FTP as the only copy of the original.
        $this->write(
            'softDeleteSource',
            fn () => $this->connection->update('UPDATE MVDContent SET Deleted = 1 WHERE ID = ?', [$mvdId]),
        );
    }

    /**
     * @param  list<string>  $mvdIds
     */
    public function deletePages(array $mvdIds): void
    {
        foreach (array_chunk(array_values($mvdIds), self::MAX_IDS_PER_DELETE) as $chunk) {
            // The only thing built into the text is a run of question marks, counted from the chunk. The
            // ids themselves stay bindings.
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            // The format guard is the seat belt. This is a real DELETE against a 140 million row table,
            // and one wrong id in the list - a source row picked up from sourceFilesFor(), say - would
            // take the PDF's MVDContent row with it and cascade its ThumbLayer away, leaving the file on
            // FTP with nothing in the database pointing at it. Only a page row can ever match, which is
            // the same predicate imagePagesFor() finds them by.
            $sql = "DELETE FROM MVDContent WHERE Format LIKE ? AND ID IN ({$placeholders})";
            $bindings = array_merge([self::FORMAT_IMAGE_LIKE], $chunk);

            $this->write('deletePages', fn () => $this->connection->delete($sql, $bindings));
        }
    }

    /**
     * @return list<SourceFile>
     */
    public function hiddenSourcesFor(string $contentId): array
    {
        // The mirror image of sourceFilesFor(): the PDF rows a conversion flagged as deleted, which is
        // how an undo finds the original it has to put back on show.
        $sql = <<<'SQL'
            SELECT ID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE ContentID = ?
              AND Deleted = 1
              AND Format LIKE ?
            ORDER BY SeqPageNo
            SQL;

        return $this->sourceFiles($sql, [$contentId, self::FORMAT_PDF_LIKE]);
    }

    public function restoreSource(string $mvdId): void
    {
        $this->write(
            'restoreSource',
            fn () => $this->connection->update('UPDATE MVDContent SET Deleted = 0 WHERE ID = ?', [$mvdId]),
        );
    }

    public function hiddenSourcesAfter(?string $afterMvdId, int $limit): array
    {
        // ID is the clustered key, so ordering and paging on it is a forward walk of the table rather
        // than a sort. Deleted and Format are almost certainly not indexed on a 140 million row table,
        // and that is fine: the whole run is one ordered pass, not a scan per page.
        $sql = <<<'SQL'
            SELECT TOP (?) ID, ContentID, SeqPageNo, PageNo, CreateDateTime, Format, FtpSiteID
            FROM MVDContent
            WHERE Deleted = 1
              AND Format LIKE ?
            SQL;

        $bindings = [$limit, self::FORMAT_PDF_LIKE];

        if ($afterMvdId !== null) {
            $sql .= "\n  AND ID > ?";
            $bindings[] = $afterMvdId;
        }

        $sql .= "\nORDER BY ID";

        return $this->sourceFiles($sql, $bindings, withContentId: true);
    }

    public function profileSourcesAfter(int $profileId, ?string $afterMvdId, int $limit): array
    {
        // Deleted is deliberately not filtered: the rows this is for were never converted, so nothing
        // ever flagged them.
        $sql = <<<'SQL'
            SELECT TOP (?) m.ID, m.ContentID, m.SeqPageNo, m.PageNo, m.CreateDateTime, m.Format, m.FtpSiteID
            FROM MVDContent m
            INNER JOIN GeneralContent g ON g.ID = m.ContentID
            WHERE g.ProfileID = ?
              AND m.Format LIKE ?
            SQL;

        $bindings = [$limit, $profileId, self::FORMAT_PDF_LIKE];

        if ($afterMvdId !== null) {
            $sql .= "\n  AND m.ID > ?";
            $bindings[] = $afterMvdId;
        }

        $sql .= "\nORDER BY m.ID";

        return $this->sourceFiles($sql, $bindings, withContentId: true);
    }

    public function deleteProfileSource(string $mvdId, int $profileId): bool
    {
        // This one cannot use Deleted = 1 as its seat belt, because the rows it is for were never
        // converted and so were never flagged. The profile takes its place, and it is a stronger
        // guard: the join means a row belonging to any other profile is not reachable by this
        // statement at all, whatever id is passed in. The Format guard stays, so a page image is
        // still unreachable and ImageLayer and ThumbLayer cannot be cascaded away by mistake.
        $sql = <<<'SQL'
            DELETE m
            FROM MVDContent m
            INNER JOIN GeneralContent g ON g.ID = m.ContentID
            WHERE m.ID = ?
              AND m.Format LIKE ?
              AND g.ProfileID = ?
            SQL;

        return $this->write(
            'deleteProfileSource',
            fn (): bool => $this->connection->delete($sql, [$mvdId, self::FORMAT_PDF_LIKE, $profileId]) > 0,
        );
    }

    public function hardDeleteSource(string $mvdId): bool
    {
        // Both guards are in the statement rather than only in the caller's query, and for the same
        // reason deletePages has its own: MVDContent is where the page images live too, and
        // ImageLayer and ThumbLayer cascade from it. An id that is not a hidden PDF row must be
        // incapable of deleting anything here, whatever the caller believes it is passing in - a
        // mistake would not lose a row, it would lose a converted document.
        //
        // Deleted = 1 carries a second meaning as well: it is the archive's record that this source
        // has been converted. A row that is still on show has pages that are not there yet.
        $sql = <<<'SQL'
            DELETE FROM MVDContent
            WHERE ID = ?
              AND Deleted = 1
              AND Format LIKE ?
            SQL;

        return $this->write(
            'hardDeleteSource',
            fn (): bool => $this->connection->delete($sql, [$mvdId, self::FORMAT_PDF_LIKE]) > 0,
        );
    }

    public function undoConverted(string $contentId): void
    {
        // Exactly what markConverted() writes, written back: the content becomes free, not viewable,
        // and below discovery's threshold again, so the next pass offers it like any other. The
        // RenderMediaId the archive uses for "not rendered" is the one its own claim query excludes -
        // anything but 1 - and 6 is what the contents waiting in the queue carry.
        $sql = <<<'SQL'
            UPDATE GeneralContent
            SET FS3dIndexItemCountThresholdStatus = 0, Reserved = ?, RenderMediaId = 6, HasView = 0, ModifyDate = GETDATE()
            WHERE ID = ?
            SQL;

        $this->write('undoConverted', fn () => $this->connection->update($sql, [self::RESERVED_FREE, $contentId]));
    }

    public function freeReservation(array $contentIds): int
    {
        $freed = 0;

        foreach (array_chunk(array_values($contentIds), self::MAX_IDS_PER_DELETE) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

            // Two seat belts, because this writes the column the archive locks and judges with:
            // a content the archive calls converted is never touched, and neither is one that has page
            // images - whatever marker it carries, that is a verdict about work that exists. Everything
            // else with a stale GUID in it is a worker that is never coming back.
            // The threshold bit goes back to 0 with it: the failure marker and the bit are written
            // together, and freeing one without the other leaves the content invisible to discovery.
            $sql = <<<SQL
                UPDATE GeneralContent
                SET Reserved = ?, FS3dIndexItemCountThresholdStatus = 0, ModifyDate = GETDATE()
                WHERE ID IN ({$placeholders})
                  AND Reserved <> ?
                  AND NOT EXISTS (
                      SELECT 1 FROM MVDContent m
                      WHERE m.ContentID = GeneralContent.ID AND m.Format LIKE ? AND m.Deleted = 0
                  )
                SQL;

            $bindings = array_merge([self::RESERVED_FREE], $chunk, [self::RESERVED_CONVERTED, self::FORMAT_IMAGE_LIKE]);

            $freed += (int) $this->write('freeReservation', fn (): int => $this->connection->update($sql, $bindings));
        }

        return $freed;
    }

    public function markConverted(string $contentId): void
    {
        // Byte-identical to spUpdateCommentByContentIDAfterCoordinate with @Type = 1. All five columns
        // matter: the viewer decides a content is viewable from RenderMediaId and HasView together, and
        // discovery skips it again through the bit column and the Reserved marker.
        // FS3dIndexItemCountThresholdStatus is a bit, so the old callers' value of 2 was stored as 1
        // anyway; 1 is written plainly.
        // PdfConvert is deliberately left alone. The queue lives in MySQL now, and writing a heap with
        // no indexes once per content would cost a full table scan for a row nothing reads.
        $sql = <<<'SQL'
            UPDATE GeneralContent
            SET FS3dIndexItemCountThresholdStatus = 1, Reserved = ?, RenderMediaId = 1, HasView = 1, ModifyDate = GETDATE()
            WHERE ID = ?
            SQL;

        $this->write('markConverted', fn () => $this->connection->update($sql, [self::RESERVED_CONVERTED, $contentId]));
    }

    public function markFailed(string $contentId): void
    {
        // Only for a final failure. The bit column is what takes the content out of discovery for good,
        // so a retryable failure must release() instead, or the content is lost until somebody clears
        // the marker by hand.
        $sql = <<<'SQL'
            UPDATE GeneralContent
            SET FS3dIndexItemCountThresholdStatus = 1, Reserved = ?, ModifyDate = GETDATE()
            WHERE ID = ?
            SQL;

        $this->write('markFailed', fn () => $this->connection->update($sql, [self::RESERVED_FAILED, $contentId]));
    }

    /**
     * The one door every write goes through. With write_mode "off" the whole pipeline can be run
     * against production as a dry run: it reads, downloads, renders and reports, and cannot change a
     * single row. The statement is held in a closure so that a blocked write never reaches the driver.
     */
    private function write(string $operation, Closure $statement): mixed
    {
        if (! $this->writesAllowed()) {
            throw new RuntimeException(
                "Archive write \"{$operation}\" was blocked: converter.archive.write_mode is not \"on\"."
            );
        }

        return $statement();
    }

    private function writesAllowed(): bool
    {
        if ($this->writeMode instanceof Closure) {
            return (bool) ($this->writeMode)();
        }

        return in_array(strtolower(trim($this->writeMode)), self::WRITE_MODES_ON, true);
    }

    /**
     * Retries a deadlock victim, and only a deadlock victim. Claiming a content and writing its pages
     * both touch rows other workers are touching; everything else that goes wrong is a real problem and
     * propagates untouched.
     */
    private function retryingDeadlocks(Closure $statement): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $statement();
            } catch (PDOException $exception) {
                // PDOException, not QueryException: Laravel's QueryException extends it, and the blob
                // inserts go straight to the driver, so a deadlock on ThumbLayer arrives as the plain
                // PDO one. Catching the parent covers both.
                if ($attempt >= $this->deadlockAttempts || ! self::isDeadlock($exception)) {
                    throw $exception;
                }

                // Backing off further each time keeps two workers that deadlocked from colliding again
                // the instant they both wake up.
                usleep($this->deadlockBackoffMicroseconds * $attempt);
            }
        }
    }

    private static function isDeadlock(PDOException $exception): bool
    {
        return ($exception->errorInfo[1] ?? null) === 1205 || (string) $exception->getCode() === '40001';
    }

    /**
     * ThumbLayer.thumb and ImageLayer.Pic are varbinary(max). pdo_sqlsrv sends a PHP string as nvarchar
     * unless the parameter is typed, which re-encodes a JPEG on the way in and stores a thumbnail no
     * viewer can decode, so the blob is bound as a LOB with the driver's binary encoding.
     *
     * It is bound on the write PDO rather than handed to the connection for a second reason that holds
     * for every driver: Laravel puts the bindings into the query log, into the QueryExecuted event, and
     * - through Str::replaceArray - into the message of a QueryException. Any of the three turns a
     * failed thumbnail insert into a log line with a JPEG in it. Here the blob never enters a bindings
     * array; the hand-written log entry carries its byte count instead.
     *
     * @param  array{0: string, 1: string}  $bindings  the page id, then the blob
     */
    private function insertBinary(string $sql, array $bindings): void
    {
        $pdo = $this->writePdo();

        if ($pdo === null) {
            $this->connection->insert($sql, $bindings);

            return;
        }

        $start = microtime(true);

        $statement = $pdo->prepare($sql);
        $statement->bindValue(1, $bindings[0], PDO::PARAM_STR);

        if (defined('PDO::SQLSRV_ENCODING_BINARY')) {
            $statement->bindParam(2, $bindings[1], PDO::PARAM_LOB, 0, PDO::SQLSRV_ENCODING_BINARY);
        } else {
            $statement->bindParam(2, $bindings[1], PDO::PARAM_LOB);
        }

        $statement->execute();

        // Logged by hand because this one statement goes around the connection. The blob is replaced by
        // its size: a 4 KB thumbnail on every page of every content would bury the log it is written to.
        $this->connection->logQuery(
            $sql,
            [$bindings[0], '<'.strlen($bindings[1]).' bytes>'],
            (microtime(true) - $start) * 1000,
        );
    }

    /**
     * The connection's write PDO, or null when the connection is not a real one (the pipeline's tests
     * hand in a stub) and the blob has to go through the ordinary prepared statement.
     */
    private function writePdo(): ?PDO
    {
        if (! $this->connection instanceof Connection) {
            return null;
        }

        $pdo = $this->connection->getPdo();

        return $pdo instanceof PDO ? $pdo : null;
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<SourceFile>
     */
    private function sourceFiles(string $sql, array $bindings, bool $withContentId = false): array
    {
        return array_map(
            // PageNo is char(200) and arrives padded to its full width, which would turn a page's
            // "1" into "1" followed by 199 spaces and an FTP folder nobody can find.
            fn (object $row): SourceFile => new SourceFile(
                self::text($row->ID),
                $row->SeqPageNo === null ? null : (int) $row->SeqPageNo,
                self::text($row->PageNo),
                self::text($row->CreateDateTime),
                self::text($row->Format),
                $row->FtpSiteID === null ? null : (int) $row->FtpSiteID,
                $withContentId ? self::text($row->ContentID) : null,
            ),
            $this->connection->select($sql, $bindings),
        );
    }

    /**
     * The clause that keeps the profiles named in converter.skip_profiles out of a discovery query,
     * or nothing at all when none are named.
     *
     * The IDs go into the statement rather than into bindings because the two queries that use this
     * build their bindings positionally and this clause sits in the middle of one and at the front of
     * the other. They are integers by the time they arrive - config casts every entry with (int) -
     * so there is nothing here a string could carry through.
     */
    private function exceptSkippedProfiles(string $column): string
    {
        $profiles = array_map('intval', (array) config('converter.skip_profiles'));

        if ($profiles === []) {
            return '';
        }

        // A content with no profile at all is still converted: only the named ones are left out.
        return "\n  AND ({$column} IS NULL OR {$column} NOT IN (".implode(', ', $profiles).'))';
    }

    private static function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private static function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        $text = self::text($value);

        return $text === '' ? null : CarbonImmutable::parse($text);
    }
}
