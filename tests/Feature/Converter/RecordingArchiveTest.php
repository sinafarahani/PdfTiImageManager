<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\RecordingArchive;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Archive\StoreMode;
use Carbon\CarbonImmutable;
use RuntimeException;
use Tests\TestCase;

/**
 * The decorator converters:try relies on: every read reaches the archive behind it, and every one of
 * the seven writes is recorded and stops there. Each write is asserted twice - once that it was
 * recorded, once that the archive behind it did not see it - because a decorator that only forgot one
 * method would still pass a test that looked at the recording alone, and that method would then run
 * against a 93 million row production database during what the operator was told was a dry run.
 */
class RecordingArchiveTest extends TestCase
{
    private const string CONTENT = '1c8f16cf-4635-42f8-971d-451b8a1b1ae1';

    private const string SOURCE_MVD = 'a47e40c9-e6a1-ed11-96cd-005056baa2b4';

    private FakeArchive $archive;

    private RecordingArchive $recording;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = new FakeArchive;
        $this->archive->addContent(self::CONTENT, profileId: 65, processDate: CarbonImmutable::parse('2026-09-01 12:00:00'));
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: 'original.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));
        $this->archive->setStoreMode(65, StoreMode::FileSystem);

        $this->recording = new RecordingArchive($this->archive);
    }

    public function test_the_reads_go_to_the_real_archive(): void
    {
        $discovered = $this->recording->discover(null, 10);
        $this->assertCount(1, $discovered);
        $this->assertSame(self::CONTENT, $discovered[0]->contentId);

        $sources = $this->recording->sourceFilesFor(self::CONTENT);
        $this->assertCount(1, $sources);
        $this->assertSame(self::SOURCE_MVD, $sources[0]->mvdId);

        $this->assertSame(65, $this->recording->profileIdFor(self::CONTENT));
        $this->assertSame(StoreMode::FileSystem, $this->recording->storeModeFor(65));
        $this->assertSame(1, $this->recording->currentFileSite()->id);
        $this->assertSame([], $this->recording->imagePagesFor(self::CONTENT));

        // A read is not a write, so none of that is in the recording.
        $this->assertSame([], $this->recording->writes());
    }

    public function test_reserve_is_recorded_and_answered_without_taking_the_content(): void
    {
        // True, so that the run gets past its first step: whether the real reservation would win is a
        // race with the workers of that moment, and not something a dry run can answer.
        $this->assertTrue($this->recording->reserve(self::CONTENT, 'try:build-pc:1'));

        $this->assertSame(['reserve'], $this->recording->operations());
        $this->assertSame(
            ['contentId' => self::CONTENT, 'owner' => 'try:build-pc:1'],
            $this->recording->writes()[0]['arguments'],
        );
        $this->assertNull($this->archive->ownerOf(self::CONTENT));
    }

    public function test_release_is_recorded_and_leaves_a_live_reservation_alone(): void
    {
        $this->archive->reserve(self::CONTENT, 'converter@build-pc:4212');

        $this->recording->release(self::CONTENT);

        $this->assertSame(['release'], $this->recording->operations());
        $this->assertSame('converter@build-pc:4212', $this->archive->ownerOf(self::CONTENT));
    }

    public function test_insert_page_is_recorded_and_answered_with_an_obviously_fake_id(): void
    {
        $first = $this->recording->insertPage($this->page(1));
        $second = $this->recording->insertPage($this->page(2));

        // Deterministic and in page order, so two dry runs of one content read the same.
        $this->assertSame('dryrun-0001-0000-0000-000000000000', $first);
        $this->assertSame('dryrun-0002-0000-0000-000000000000', $second);
        $this->assertSame([$first, $second], array_keys($this->recording->insertedPages()));
        $this->assertSame(2, $this->recording->insertedPages()[$second]->seqPageNo);

        // Nothing reached the archive: no page row, and so nothing for imagePagesFor() to find.
        $this->assertSame([], $this->archive->pages());
        $this->assertSame([], $this->archive->imagePagesFor(self::CONTENT));

        $arguments = $this->recording->writes()[0]['arguments'];
        $this->assertSame(['insertPage', 'insertPage'], $this->recording->operations());
        $this->assertSame(self::CONTENT, $arguments['contentId']);
        $this->assertSame('1', $arguments['pageNo']);
        $this->assertSame('Image/jpg', $arguments['format']);
        $this->assertSame(1, $arguments['ftpSiteId']);
        $this->assertSame($first, $arguments['mvdId']);

        // The blobs are reduced to their byte counts: the report prints these arguments, and a
        // thumbnail per page would bury it.
        $this->assertSame(strlen('thumb-bytes'), $arguments['thumbnailBytes']);
        $this->assertNull($arguments['imageBytes']);
        $this->assertNotContains('thumb-bytes', $arguments);
    }

    public function test_a_page_without_a_thumbnail_is_refused_the_way_the_archive_refuses_it(): void
    {
        // A dry run that accepted a page the archive would reject would promise a conversion that
        // cannot happen.
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no thumbnail');

        $this->recording->insertPage(new PageInsert(
            contentId: self::CONTENT,
            seqPageNo: 1,
            createDateTime: '2026-09-12 10:11:12',
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: '',
        ));
    }

    public function test_soft_delete_source_is_recorded_and_the_source_stays_visible(): void
    {
        $this->recording->softDeleteSource(self::SOURCE_MVD);

        $this->assertSame(['softDeleteSource'], $this->recording->operations());
        $this->assertSame([], $this->archive->softDeletedSources());
        $this->assertCount(1, $this->archive->sourceFilesFor(self::CONTENT));
    }

    public function test_delete_pages_is_recorded_and_removes_nothing(): void
    {
        $standing = $this->archive->insertPage($this->page(1));

        $this->recording->deletePages([$standing]);

        $this->assertSame(['deletePages'], $this->recording->operations());
        $this->assertSame(
            ['mvdIds' => [$standing], 'pages' => 1],
            $this->recording->writes()[0]['arguments'],
        );
        $this->assertArrayHasKey($standing, $this->archive->pages());
    }

    public function test_mark_converted_and_mark_failed_are_recorded_only(): void
    {
        $this->recording->markConverted(self::CONTENT);
        $this->recording->markFailed(self::CONTENT);

        $this->assertSame(['markConverted', 'markFailed'], $this->recording->operations());
        $this->assertSame([], $this->archive->convertedContents());
        $this->assertSame([], $this->archive->failedContents());

        // Both markers take a content out of discovery for good; the content is still discoverable.
        $this->assertCount(1, $this->archive->discover(null, 10));
    }

    private function page(int $seq): PageInsert
    {
        return new PageInsert(
            contentId: self::CONTENT,
            seqPageNo: $seq,
            createDateTime: '2026-09-12 10:11:12',
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: 'thumb-bytes',
        );
    }
}
