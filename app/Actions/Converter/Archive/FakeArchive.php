<?php

namespace App\Actions\Converter\Archive;

use Carbon\CarbonImmutable;
use RuntimeException;

/**
 * The archive, in memory. It is what the pipeline's tests run against and what a dev machine without
 * pdo_sqlsrv (or without a route to the archive) uses, so it keeps the rules that actually matter:
 * a content can be reserved exactly once, a page row gets a generated id that arrives in sequence,
 * and deleting pages really removes them.
 *
 * It is not a SQL Server emulator. Anything the pipeline is not allowed to depend on - collation,
 * padding, deadlocks - is simply absent.
 */
class FakeArchive implements ArchiveGateway
{
    /** @var array<string, DiscoveredContent> */
    private array $contents = [];

    /** @var list<DiscoveredContent> */
    private array $legacyQueue = [];

    /** @var array<string, string> content id => the owner holding it */
    private array $reservations = [];

    /** @var array<string, list<SourceFile>> content id => its source rows */
    private array $sourceFiles = [];

    /** @var array<string, PageInsert> generated MVDContent id => the page written under it */
    private array $pages = [];

    /** @var list<string> */
    private array $converted = [];

    /** @var list<string> */
    private array $failed = [];

    /** @var list<string> */
    private array $softDeleted = [];

    /** @var list<string> source rows put back on show by an undo */
    private array $restored = [];

    /** @var list<string> contents taken back to "not converted" */
    private array $undone = [];

    /** @var list<string> contents whose reservation was freed */
    private array $freed = [];

    /** @var array<int, StoreMode> */
    private array $storeModes = [];

    private FtpSite $site;

    private int $writtenPages = 0;

    public function __construct(?FtpSite $site = null, private StoreMode $defaultStoreMode = StoreMode::FileSystem)
    {
        // The live row of FtpSites, so a fake run produces the same paths a real one would.
        $this->site = $site ?? new FtpSite(1, 'Archive-app', 21, 'DOI', 'archive', 'secret');
    }

    public function addContent(string $contentId, ?int $profileId = 1, ?CarbonImmutable $processDate = null): self
    {
        $this->contents[$contentId] = new DiscoveredContent($contentId, $profileId, $processDate);

        return $this;
    }

    public function addLegacyContent(string $contentId, ?int $profileId = 1, ?CarbonImmutable $processDate = null): self
    {
        $this->legacyQueue[] = new DiscoveredContent($contentId, $profileId, $processDate);

        return $this;
    }

    public function addSourceFile(string $contentId, SourceFile $file): self
    {
        $this->sourceFiles[$contentId][] = $file;

        return $this;
    }

    public function setStoreMode(int $profileId, StoreMode $mode): self
    {
        $this->storeModes[$profileId] = $mode;

        return $this;
    }

    public function setSite(FtpSite $site): self
    {
        $this->site = $site;

        return $this;
    }

    /**
     * The pages still standing, in the order they were written.
     *
     * @return array<string, PageInsert>
     */
    public function pages(): array
    {
        return $this->pages;
    }

    /**
     * The worker currently holding the content, or null while it is free.
     */
    public function ownerOf(string $contentId): ?string
    {
        return $this->reservations[$contentId] ?? null;
    }

    /**
     * @return list<string>
     */
    public function convertedContents(): array
    {
        return $this->converted;
    }

    /**
     * @return list<string>
     */
    public function failedContents(): array
    {
        return $this->failed;
    }

    /**
     * @return list<string>
     */
    public function softDeletedSources(): array
    {
        return $this->softDeleted;
    }

    /**
     * @return list<string>
     */
    public function restoredSources(): array
    {
        return $this->restored;
    }

    /**
     * @return list<string>
     */
    public function undoneContents(): array
    {
        return $this->undone;
    }

    /**
     * @return list<string>
     */
    public function freedContents(): array
    {
        return $this->freed;
    }

    /**
     * @return list<DiscoveredContent>
     */
    public function discover(?CarbonImmutable $processedAfter, int $limit): array
    {
        $found = array_values(array_filter(
            $this->contents,
            fn (DiscoveredContent $content): bool => ! isset($this->reservations[$content->contentId])
                && ($processedAfter === null
                    || ($content->processDate !== null && $content->processDate->greaterThan($processedAfter))),
        ));

        usort(
            $found,
            fn (DiscoveredContent $a, DiscoveredContent $b): int => ($a->processDate?->getTimestamp() ?? 0) <=> ($b->processDate?->getTimestamp() ?? 0),
        );

        return array_slice($found, 0, $limit);
    }

    /**
     * @return list<DiscoveredContent>
     */
    public function seedFromLegacyQueue(int $limit, int $offset): array
    {
        return array_slice($this->legacyQueue, $offset, $limit);
    }

    public function reserve(string $contentId, string $owner): bool
    {
        if (! isset($this->contents[$contentId]) || isset($this->reservations[$contentId])) {
            return false;
        }

        // Deliberately not "unless it is the same owner": the real UPDATE compares against the free
        // GUID, so a worker that asks twice loses the second time as surely as a stranger would.
        $this->reservations[$contentId] = $owner;

        return true;
    }

    public function release(string $contentId): void
    {
        unset($this->reservations[$contentId]);
    }

    /**
     * @return list<SourceFile>
     */
    public function sourceFilesFor(string $contentId): array
    {
        $files = array_values(array_filter(
            $this->sourceFiles[$contentId] ?? [],
            fn (SourceFile $file): bool => ! in_array($file->mvdId, $this->softDeleted, true),
        ));

        // SeqPageNo order, because that is what the real query is sorted by. A fake that answered in
        // insertion order would let a pipeline test pass on an order production never gives.
        usort($files, fn (SourceFile $a, SourceFile $b): int => ($a->seqPageNo ?? 0) <=> ($b->seqPageNo ?? 0));

        return $files;
    }

    /**
     * @return list<SourceFile>
     */
    public function imagePagesFor(string $contentId): array
    {
        $pages = [];

        foreach ($this->pages as $mvdId => $page) {
            if ($page->contentId === $contentId) {
                $pages[] = new SourceFile(
                    $mvdId,
                    $page->seqPageNo,
                    $page->pageNo(),
                    $page->createDateTime,
                    $page->format,
                    $page->ftpSiteId,
                );
            }
        }

        return $pages;
    }

    public function profileIdFor(string $contentId): ?int
    {
        return $this->contents[$contentId]->profileId ?? null;
    }

    public function storeModeFor(int $profileId): StoreMode
    {
        return $this->storeModes[$profileId] ?? $this->defaultStoreMode;
    }

    public function currentFileSite(): FtpSite
    {
        return $this->site;
    }

    public function insertPage(PageInsert $page): string
    {
        // The same refusal SqlServerArchive makes: every archive page has a ThumbLayer row, so a fake
        // that accepted a page without a thumbnail would be more forgiving than the database.
        if ($page->thumbnail === '') {
            throw new RuntimeException(
                "Page {$page->seqPageNo} of content {$page->contentId} has no thumbnail; the archive requires one for every page."
            );
        }

        // Sequential, like newsequentialid(), so a test that sorts pages by id gets them in page order.
        $mvdId = sprintf('00000000-0000-4000-8000-%012d', ++$this->writtenPages);

        $this->pages[$mvdId] = $page;

        return $mvdId;
    }

    public function softDeleteSource(string $mvdId): void
    {
        $this->softDeleted[] = $mvdId;
    }

    /**
     * @param  list<string>  $mvdIds
     */
    public function deletePages(array $mvdIds): void
    {
        foreach ($mvdIds as $mvdId) {
            unset($this->pages[$mvdId]);
        }
    }

    public function hiddenSourcesFor(string $contentId): array
    {
        return array_values(array_filter(
            $this->sourceFiles[$contentId] ?? [],
            fn (SourceFile $file): bool => $file->isPdf() && in_array($file->mvdId, $this->softDeleted, true),
        ));
    }

    public function restoreSource(string $mvdId): void
    {
        $this->softDeleted = array_values(array_diff($this->softDeleted, [$mvdId]));
        $this->restored[] = $mvdId;
    }

    public function undoConverted(string $contentId): void
    {
        // The twin of markConverted: the content becomes discoverable again, which is what makes an
        // undo followed by a retry behave like a content that was never converted.
        $this->converted = array_values(array_diff($this->converted, [$contentId]));
        $this->failed = array_values(array_diff($this->failed, [$contentId]));
        unset($this->reservations[$contentId]);
        $this->undone[] = $contentId;
    }

    public function freeReservation(array $contentIds): int
    {
        $freed = 0;

        foreach ($contentIds as $contentId) {
            // The real one refuses a content the archive calls converted, or one that has page images.
            if (in_array($contentId, $this->converted, true) || $this->imagePagesFor($contentId) !== []) {
                continue;
            }

            $this->failed = array_values(array_diff($this->failed, [$contentId]));
            unset($this->reservations[$contentId]);
            $this->freed[] = $contentId;
            $freed++;
        }

        return $freed;
    }

    public function markConverted(string $contentId): void
    {
        $this->finish($contentId);

        $this->converted[] = $contentId;
    }

    public function markFailed(string $contentId): void
    {
        $this->finish($contentId);

        $this->failed[] = $contentId;
    }

    /**
     * Both markers take the content out of discovery for good - one through RenderMediaId, the other
     * through the bit column - so the fake drops it rather than keep handing it back.
     */
    private function finish(string $contentId): void
    {
        if (! isset($this->contents[$contentId])) {
            throw new RuntimeException("Unknown content \"{$contentId}\".");
        }

        unset($this->contents[$contentId], $this->reservations[$contentId]);
    }
}
