<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Archive\StoreMode;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The behaviour the rest of the pipeline is allowed to rely on. FakeArchive is what the pipeline's
 * own tests run against, so a fake that were more forgiving than SQL Server - handing the same
 * content to two workers, say - would hide exactly the bug those tests exist to catch.
 */
class FakeArchiveTest extends TestCase
{
    public function test_a_content_can_only_be_reserved_once(): void
    {
        $archive = (new FakeArchive)->addContent('C1');

        $this->assertTrue($archive->reserve('C1', 'worker-1'));
        $this->assertFalse($archive->reserve('C1', 'worker-2'));
        $this->assertFalse($archive->reserve('C1', 'worker-1'));
        $this->assertSame('worker-1', $archive->ownerOf('C1'));
    }

    public function test_a_released_content_is_free_again(): void
    {
        $archive = (new FakeArchive)->addContent('C1');

        $archive->reserve('C1', 'worker-1');
        $archive->release('C1');

        $this->assertNull($archive->ownerOf('C1'));
        $this->assertTrue($archive->reserve('C1', 'worker-2'));
    }

    public function test_an_unknown_content_cannot_be_reserved(): void
    {
        $this->assertFalse((new FakeArchive)->reserve('C-missing', 'worker-1'));
    }

    public function test_discovery_returns_free_contents_oldest_first(): void
    {
        $archive = (new FakeArchive)
            ->addContent('C-new', 1, CarbonImmutable::parse('2025-03-01 09:00:00'))
            ->addContent('C-old', 1, CarbonImmutable::parse('2025-01-07 16:39:53'))
            ->addContent('C-held', 1, CarbonImmutable::parse('2025-02-01 09:00:00'));

        $archive->reserve('C-held', 'worker-1');

        $this->assertSame(['C-old', 'C-new'], array_column($archive->discover(null, 10), 'contentId'));
        $this->assertSame(
            ['C-new'],
            array_column($archive->discover(CarbonImmutable::parse('2025-02-15 00:00:00'), 10), 'contentId'),
        );
        $this->assertCount(1, $archive->discover(null, 1));
    }

    public function test_the_legacy_queue_is_paged(): void
    {
        $archive = (new FakeArchive)
            ->addLegacyContent('C1')
            ->addLegacyContent('C2')
            ->addLegacyContent('C3');

        $this->assertSame(['C2', 'C3'], array_column($archive->seedFromLegacyQueue(10, 1), 'contentId'));
    }

    public function test_written_pages_come_back_with_the_ids_they_were_given(): void
    {
        $archive = (new FakeArchive)->addContent('C1');

        $first = $archive->insertPage(new PageInsert('C1', 1, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-1'));
        $second = $archive->insertPage(new PageInsert('C1', 2, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-2'));

        $this->assertNotSame($first, $second);
        $this->assertSame([$first, $second], array_keys($archive->pages()));
        $this->assertSame('thumb-2', $archive->pages()[$second]->thumbnail);

        $pages = $archive->imagePagesFor('C1');

        $this->assertSame([$first, $second], array_column($pages, 'mvdId'));
        $this->assertSame(['1', '2'], array_column($pages, 'pageNo'));
        $this->assertSame($first.'.jpg', $pages[0]->remoteFileName());
    }

    public function test_deleting_pages_removes_them(): void
    {
        $archive = (new FakeArchive)->addContent('C1');

        $first = $archive->insertPage(new PageInsert('C1', 1, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-1'));
        $second = $archive->insertPage(new PageInsert('C1', 2, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-2'));

        $archive->deletePages([$first]);

        $this->assertSame([$second], array_keys($archive->pages()));
        $this->assertSame([$second], array_column($archive->imagePagesFor('C1'), 'mvdId'));

        $archive->deletePages([$second]);

        $this->assertSame([], $archive->pages());
    }

    public function test_a_page_without_a_thumbnail_is_refused(): void
    {
        $archive = (new FakeArchive)->addContent('C1');

        // The database gives every page a ThumbLayer row, so the fake must not be the more forgiving
        // of the two: a pipeline bug that loses a thumbnail has to fail here, not in the viewer.
        $this->expectExceptionMessage('has no thumbnail');

        $archive->insertPage(new PageInsert('C1', 1, '2025-01-07 16:39:53', 'Image/jpg', 1, ''));
    }

    public function test_source_files_come_back_in_page_order(): void
    {
        $archive = (new FakeArchive)
            ->addContent('C1')
            ->addSourceFile('C1', new SourceFile('F2', 2, 'second.pdf', '2025-01-07 16:39:53', 'Application/pdf', 1))
            ->addSourceFile('C1', new SourceFile('F1', 1, 'first.pdf', '2025-01-07 16:39:53', 'Application/pdf', 1));

        // The real query is ORDER BY SeqPageNo; insertion order here would let a pipeline test pass on
        // an order production never produces.
        $this->assertSame(['F1', 'F2'], array_column($archive->sourceFilesFor('C1'), 'mvdId'));
    }

    public function test_a_soft_deleted_source_stops_being_listed(): void
    {
        $archive = (new FakeArchive)
            ->addContent('C1')
            ->addSourceFile('C1', new SourceFile('F1', 0, 'book.pdf', '2025-01-07 16:39:53', 'Application/pdf', 1));

        $this->assertCount(1, $archive->sourceFilesFor('C1'));

        $archive->softDeleteSource('F1');

        $this->assertSame([], $archive->sourceFilesFor('C1'));
        $this->assertSame(['F1'], $archive->softDeletedSources());
    }

    public function test_a_converted_content_is_not_discovered_again(): void
    {
        $archive = (new FakeArchive)->addContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'));

        $archive->reserve('C1', 'worker-1');
        $archive->markConverted('C1');

        $this->assertSame(['C1'], $archive->convertedContents());
        $this->assertSame([], $archive->discover(null, 10));
        $this->assertFalse($archive->reserve('C1', 'worker-2'));
    }

    public function test_a_failed_content_is_not_discovered_again(): void
    {
        $archive = (new FakeArchive)->addContent('C1', 1, CarbonImmutable::parse('2025-01-07 16:39:53'));

        $archive->markFailed('C1');

        $this->assertSame(['C1'], $archive->failedContents());
        $this->assertSame([], $archive->discover(null, 10));
    }

    public function test_profiles_and_the_current_site_are_answered(): void
    {
        $archive = (new FakeArchive)
            ->addContent('C1', 7)
            ->setStoreMode(7, StoreMode::Database);

        $this->assertSame(7, $archive->profileIdFor('C1'));
        $this->assertNull($archive->profileIdFor('C-missing'));
        $this->assertSame(StoreMode::Database, $archive->storeModeFor(7));
        $this->assertSame(StoreMode::FileSystem, $archive->storeModeFor(8));
        $this->assertSame('DOI', $archive->currentFileSite()->folder);
    }
}
