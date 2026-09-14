<?php

namespace App\Actions\Converter\Archive;

use Carbon\CarbonImmutable;

/**
 * Everything the converter does to the archive database. The implementations write the SQL directly
 * instead of calling the archive's stored procedures, so that the work queue can be claimed without
 * deadlocking and a failure can be recorded truthfully.
 *
 * The archive's own conventions are kept exactly: a page row is an MVDContent row whose ID the
 * database generates (the column defaults to newsequentialid() and is the clustered key of a table
 * with 140 million rows, so the IDs must stay sequential), the thumbnail goes to ThumbLayer, the
 * image goes to ImageLayer only for profiles that store images in the database, and a converted
 * content is marked with RenderMediaId = 1, HasView = 1 and Reserved = the archive's "converted" GUID.
 */
interface ArchiveGateway
{
    /**
     * Contents that still need converting, with a ProcessDate after $processedAfter (null scans from
     * the beginning). Oldest first.
     *
     * @return list<DiscoveredContent>
     */
    public function discover(?CarbonImmutable $processedAfter, int $limit): array;

    /**
     * Contents the previous pipeline had already found and not yet converted (PdfConvert, state 0).
     * Used once to fill an empty queue: far cheaper than scanning GeneralContent.
     *
     * @return list<DiscoveredContent>
     */
    public function seedFromLegacyQueue(int $limit, int $offset): array;

    /**
     * Takes the content for this worker by setting GeneralContent.Reserved, but only while it is
     * still free. False means somebody else has it.
     */
    public function reserve(string $contentId, string $owner): bool;

    /**
     * Puts a reserved content back: it becomes available again, for us or for anyone else.
     */
    public function release(string $contentId): void;

    /**
     * The content's source files, i.e. its MVDContent rows that are not deleted. The PDF row's
     * PageNo holds the original file name and its CreateDateTime decides the FTP folder.
     *
     * @return list<SourceFile>
     */
    public function sourceFilesFor(string $contentId): array;

    /**
     * The image pages a previous attempt already wrote for this content, so they can be removed
     * before it is converted again.
     *
     * @return list<SourceFile>
     */
    public function imagePagesFor(string $contentId): array;

    /**
     * The MVDContent ids of the content's page images that are still on show.
     *
     * imagePagesFor() deliberately includes hidden rows, because a rollback has to find an
     * interrupted attempt's leftovers. That makes it exactly the wrong question to ask before
     * destroying something: a hidden page row is a page the archive no longer shows.
     *
     * @return list<string>
     */
    public function livePageIdsFor(string $contentId): array;

    /**
     * Whether an MVDContent row exists at all, hidden or not.
     *
     * For deciding what an interrupted destructive run actually managed to do. It cannot be answered
     * with hiddenSourcesFor(), which only sees rows flagged deleted: a source somebody has since put
     * back on show would read as gone.
     */
    public function sourceRowExists(string $mvdId): bool;

    /**
     * Why the contents older than $before are not being offered for conversion.
     *
     * Discovery orders by ProcessDate and takes the oldest that still need converting, so where it
     * starts is a fact about the archive rather than about the scan - but "the oldest work is from
     * July" and "the scan cannot see anything before July" look identical from outside. This counts
     * the older contents against each of discovery's own conditions and says which one accounts for
     * them.
     *
     * One pass over the join, and an expensive one on a 93 million row table. It is a diagnostic
     * somebody runs when they doubt the coverage, not something on a schedule.
     *
     * @return array{total: int, converted: int, reserved: int, convertedMarker: int, failedMarker: int, heldByWorker: int, threshold: int, offered: int}
     */
    public function discoveryBreakdown(CarbonImmutable $before): array;

    public function profileIdFor(string $contentId): ?int;

    public function storeModeFor(int $profileId): StoreMode;

    /**
     * The FTP site the archive currently uses for files.
     */
    public function currentFileSite(): FtpSite;

    /**
     * Writes one page row (MVDContent + ThumbLayer, and ImageLayer when the image is stored in the
     * database) and returns the MVDContent.ID the archive generated. That ID is the name the page
     * image is uploaded under.
     */
    public function insertPage(PageInsert $page): string;

    /**
     * Marks the source PDF row deleted, which is how the archive hides it once its pages exist.
     */
    public function softDeleteSource(string $mvdId): void;

    /**
     * Removes page rows this pipeline created. ImageLayer and ThumbLayer rows follow through their
     * cascading foreign keys, so this is the whole undo.
     *
     * @param  list<string>  $mvdIds
     */
    public function deletePages(array $mvdIds): void;

    public function markConverted(string $contentId): void;

    public function markFailed(string $contentId): void;

    /**
     * Frees contents nothing is working on any more, so they can be offered again, and says how many
     * were freed.
     *
     * GeneralContent.Reserved is the archive's lock and its verdict at once. A worker that died with a
     * content in its hands leaves its own GUID there for ever - the retired pipeline left thousands
     * that way - and a content this pipeline gave up on carries the failure marker. Neither can be
     * reserved again, so both are invisible to discovery and unreachable by a retry.
     *
     * A content that already has page images is never touched, whatever its marker says: that is the
     * one state where the reservation is a verdict about work that exists.
     *
     * @param  list<string>  $contentIds
     */
    public function freeReservation(array $contentIds): int;

    /**
     * The content's source PDF rows that are currently hidden, i.e. the ones a conversion flagged as
     * deleted. An undo needs them, and sourceFilesFor() cannot return them by definition.
     *
     * @return list<SourceFile>
     */
    public function hiddenSourcesFor(string $contentId): array;

    /**
     * Puts a source PDF back on show, for undoing a conversion: the row is the archive's only record
     * of the original file.
     */
    public function restoreSource(string $mvdId): void;

    /**
     * Removes a converted content's source PDF row for good, and says whether there was one to remove.
     *
     * This is the only irreversible thing in the gateway. Everything else can be undone - pages are
     * deleted and written again, a reservation is released, a flag is unset - but a row deleted here
     * is gone, and with it the original file's name and the CreateDateTime that says which folder its
     * file was in. The caller is expected to have recorded both first.
     *
     * The implementation must refuse to delete anything that is not a hidden PDF row. MVDContent is
     * the table the page images live in too, and ImageLayer and ThumbLayer cascade from it, so a
     * mistake here does not delete a row: it deletes a document.
     */
    public function hardDeleteSource(string $mvdId): bool;

    /**
     * Hidden source PDF rows read straight from the archive, in ID order, starting after $afterMvdId.
     *
     * The panel's own queue only ever held contents that needed converting, so it cannot see the
     * millions the retired C# pipeline converted years ago - and those are exactly where the oldest
     * rows whose files have since been deleted by hand are. This walks MVDContent itself instead.
     *
     * ID is the clustered key and defaults to newsequentialid(), so paging on it resumes where the
     * last page stopped and the whole run costs one ordered pass rather than a scan per page.
     *
     * @return list<SourceFile>
     */
    public function hiddenSourcesAfter(?string $afterMvdId, int $limit): array;

    /**
     * Every source PDF row of one profile, hidden or not, in ID order, starting after $afterMvdId.
     *
     * For a profile whose documents were removed wholesale: its rows were never converted, so nothing
     * ever flagged them, and Deleted = 0 is the state they are stuck in.
     *
     * @return list<SourceFile>
     */
    public function profileSourcesAfter(int $profileId, ?string $afterMvdId, int $limit): array;

    /**
     * Removes a source PDF row of one named profile, whatever its Deleted flag says, and reports
     * whether there was one to remove.
     *
     * Separate from hardDeleteSource() because it gives up that method's Deleted = 1 seat belt, and
     * that seat belt is most of what makes deleting from MVDContent safe. What replaces it is the
     * profile: the statement joins GeneralContent and matches on ProfileID, so a row belonging to any
     * other profile cannot be deleted by this call however it is used.
     */
    public function deleteProfileSource(string $mvdId, int $profileId): bool;

    /**
     * Takes a content back to "not converted": the marker, the viewable flags and the threshold bit
     * that markConverted() set, so discovery can offer it again. Everything markConverted writes,
     * written back.
     */
    public function undoConverted(string $contentId): void;
}
