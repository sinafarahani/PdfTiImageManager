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
}
