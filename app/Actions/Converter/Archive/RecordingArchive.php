<?php

namespace App\Actions\Converter\Archive;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The archive, read for real and written nowhere: every read goes to the gateway this wraps, every
 * write is recorded, answered, and dropped.
 *
 * It is what makes converters:try worth running. A dry run that stubbed the reads as well would only
 * prove that the code runs; here the content is looked up in the live archive, its PDF really is
 * downloaded and really is rendered, and the report shows the exact rows the same run would have
 * created - while the 93 million row archive is not touched at all.
 *
 * This is deliberately not the same thing as converter.archive.write_mode. That flag makes
 * SqlServerArchive throw on a write, which ends a run at its first page and shows the operator one
 * row of the plan; here the write is answered instead, so the run reaches the end and the whole plan
 * can be seen. The two guards are independent on purpose - a dry run through this decorator is safe
 * whatever the write mode says, and the write mode still protects the live pipeline.
 */
class RecordingArchive implements ArchiveGateway
{
    /**
     * @var list<array{operation: string, arguments: array<string, mixed>}>
     */
    private array $writes = [];

    /**
     * @var array<string, PageInsert>
     */
    private array $insertedPages = [];

    private int $pagesInserted = 0;

    public function __construct(private readonly ArchiveGateway $archive) {}

    /**
     * Every write this run asked for, in the order it asked. The arguments are scalars only: the
     * report prints them and they may reach a log, so a page's blobs are reduced to their byte counts
     * here - a 4 KB thumbnail per page would bury the very report it is printed in, which is the same
     * reason SqlServerArchive keeps them out of its query log.
     *
     * @return list<array{operation: string, arguments: array<string, mixed>}>
     */
    public function writes(): array
    {
        return $this->writes;
    }

    /**
     * The names of those writes, for a caller that only wants to count them.
     *
     * @return list<string>
     */
    public function operations(): array
    {
        return array_map(fn (array $write): string => $write['operation'], $this->writes);
    }

    /**
     * The page rows this run would have created, keyed by the fake MVDContent id each was answered
     * with. The PageInsert is kept whole here, blobs included, because the report needs the thumbnail
     * size and a "db" profile's image size.
     *
     * @return array<string, PageInsert>
     */
    public function insertedPages(): array
    {
        return $this->insertedPages;
    }

    /**
     * @return list<DiscoveredContent>
     */
    public function discover(?CarbonImmutable $processedAfter, int $limit): array
    {
        return $this->archive->discover($processedAfter, $limit);
    }

    /**
     * @return list<DiscoveredContent>
     */
    public function seedFromLegacyQueue(int $limit, int $offset): array
    {
        return $this->archive->seedFromLegacyQueue($limit, $offset);
    }

    /**
     * Recorded, and answered with "you have it". A refusal here would end the run at its first step
     * and report nothing about the content, and whether the real reservation would win is not
     * something a dry run can decide anyway: it is a race with the workers running at that moment.
     */
    public function reserve(string $contentId, string $owner): bool
    {
        $this->record('reserve', ['contentId' => $contentId, 'owner' => $owner]);

        return true;
    }

    public function release(string $contentId): void
    {
        $this->record('release', ['contentId' => $contentId]);
    }

    /**
     * @return list<SourceFile>
     */
    public function sourceFilesFor(string $contentId): array
    {
        return $this->archive->sourceFilesFor($contentId);
    }

    /**
     * @return list<SourceFile>
     */
    public function imagePagesFor(string $contentId): array
    {
        return $this->archive->imagePagesFor($contentId);
    }

    public function profileIdFor(string $contentId): ?int
    {
        return $this->archive->profileIdFor($contentId);
    }

    public function storeModeFor(int $profileId): StoreMode
    {
        return $this->archive->storeModeFor($profileId);
    }

    public function currentFileSite(): FtpSite
    {
        return $this->archive->currentFileSite();
    }

    public function insertPage(PageInsert $page): string
    {
        // The two refusals SqlServerArchive makes, kept here as well: a dry run that accepted a page
        // the archive would reject would report a conversion that cannot actually happen, which is
        // worse than no dry run at all.
        if ($page->thumbnail === '') {
            throw new RuntimeException(
                "Page {$page->seqPageNo} of content {$page->contentId} has no thumbnail; the archive requires one for every page."
            );
        }

        if ($page->image === '') {
            throw new RuntimeException(
                "Page {$page->seqPageNo} of content {$page->contentId} has an empty image; ImageLayer holds the only copy for a \"db\" profile."
            );
        }

        // Deterministic, so two dry runs of one content read the same, and unmistakable in a report
        // or a log. The real id cannot be known in advance - MVDContent.ID defaults to
        // newsequentialid() and the archive generates it on the insert - so every remote path built
        // from this one is right in every part except the file name, and the report says so.
        $mvdId = sprintf('dryrun-%04d-0000-0000-000000000000', ++$this->pagesInserted);

        $this->insertedPages[$mvdId] = $page;

        $this->record('insertPage', [
            'mvdId' => $mvdId,
            'contentId' => $page->contentId,
            'seqPageNo' => $page->seqPageNo,
            'pageNo' => $page->pageNo(),
            'createDateTime' => $page->createDateTime,
            'format' => $page->format,
            'ftpSiteId' => $page->ftpSiteId,
            'thumbnailBytes' => strlen($page->thumbnail),
            'imageBytes' => $page->image === null ? null : strlen($page->image),
        ]);

        return $mvdId;
    }

    public function softDeleteSource(string $mvdId): void
    {
        $this->record('softDeleteSource', ['mvdId' => $mvdId]);
    }

    /**
     * @param  list<string>  $mvdIds
     */
    public function deletePages(array $mvdIds): void
    {
        $this->record('deletePages', ['mvdIds' => array_values($mvdIds), 'pages' => count($mvdIds)]);
    }

    public function markConverted(string $contentId): void
    {
        $this->record('markConverted', ['contentId' => $contentId]);
    }

    public function markFailed(string $contentId): void
    {
        $this->record('markFailed', ['contentId' => $contentId]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function record(string $operation, array $arguments): void
    {
        $this->writes[] = ['operation' => $operation, 'arguments' => $arguments];
    }
}
