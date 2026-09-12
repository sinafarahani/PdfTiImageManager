<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ContentWorkspace;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\ConvertOneContent;
use App\Actions\Converter\Pipeline\Stage;
use App\Actions\Converter\Render\FakePageRenderer;
use App\Actions\Converter\Render\FakeThumbnailer;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\RenderFailed;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use RuntimeException;
use Tests\TestCase;

class ConvertOneContentTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '1c8f16cf-4635-42f8-971d-451b8a1b1ae1';

    private const string SOURCE_MVD = 'a47e40c9-e6a1-ed11-96cd-005056baa2b4';

    private const string SECOND_MVD = 'b58f51da-f7b2-fe22-a7de-116167cbb3c5';

    private const string CREATED = '2023-02-01 07:43:38';

    private string $store;

    private string $workspaceRoot;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-store-'.uniqid();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-work-'.uniqid();
        File::ensureDirectoryExists($this->store);

        config(['converter.workspace.root' => $this->workspaceRoot]);

        // The source PDF, where the archive says it is: <CreateDateTime folder>/<its own id>.pdf
        $this->archive = new FakeArchive;
        $this->archive->addContent(self::CONTENT, profileId: 65);
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: '13870611_16_ettelaat_pdf_zamimeh_49.pdf',
            createDateTime: self::CREATED,
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));

        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, '2023/02/01/07/43/38');
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf', 'a pdf');

        Sleep::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        File::deleteDirectory($this->workspaceRoot);

        parent::tearDown();
    }

    public function test_converts_a_content_into_pages_rows_and_uploaded_images(): void
    {
        $conversion = $this->claimedConversion();

        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(3, $conversion->pages);
        $this->assertSame([self::CONTENT], $this->archive->convertedContents());
        $this->assertSame([self::SOURCE_MVD], $this->archive->softDeletedSources());

        // One archive page row per rendered page, numbered from 1, with the archive's own format.
        $written = $this->archive->pages();
        $this->assertCount(3, $written);
        $this->assertSame([1, 2, 3], array_map(fn (PageInsert $page): int => $page->seqPageNo, array_values($written)));

        foreach ($written as $page) {
            $this->assertSame('Image/jpg', $page->format);
            $this->assertNotSame('', $page->thumbnail);

            // This profile keeps its images on the file store, so no image goes into the database.
            $this->assertNull($page->image);
        }

        // Every page was uploaded under its own MVDContent id, into the folder built from the very
        // timestamp stored on its row - a row can never point at a folder its file is not in.
        $ids = array_keys($written);
        $folder = SourceFile::folderFor(array_values($written)[0]->createDateTime);
        $this->assertCount(3, $conversion->pages()->get());

        foreach ($conversion->pages()->orderBy('seq')->get() as $index => $page) {
            $this->assertTrue($page->uploaded);
            $this->assertSame($ids[$index], $page->mvd_id);
            $this->assertSame($folder.'/'.$page->mvd_id.'.jpg', $page->remote_path);
            $this->assertFileExists($this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, (string) $page->remote_path));
        }

        $this->assertDirectoryDoesNotExist($this->workspaceRoot.DIRECTORY_SEPARATOR.self::CONTENT);
    }

    public function test_a_content_without_a_pdf_source_is_failed_instead_of_marked_converted(): void
    {
        // The old pipeline marked this converted, which hid the document for good.
        $archive = new FakeArchive;
        $archive->addContent(self::CONTENT, profileId: 65);
        $this->archive = $archive;

        $conversion = $this->claimedConversion();

        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(Stage::Metadata, $conversion->failure_stage);
        $this->assertSame([], $archive->convertedContents());
        $this->assertSame([self::CONTENT], $archive->failedContents());
    }

    public function test_an_upload_failure_leaves_no_page_rows_and_no_files_behind(): void
    {
        $store = $this->failingStore(failOnUpload: 2);
        $conversion = $this->claimedConversion();

        $this->pipeline(store: $store)->convert($conversion);

        $conversion->refresh();

        // A transient failure: the content goes back in the queue and is free in the archive again.
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(Stage::Upload, $conversion->failure_stage);
        $this->assertSame(1, $conversion->attempts);
        $this->assertNull($this->archive->ownerOf(self::CONTENT));
        $this->assertSame([], $this->archive->convertedContents());

        // Nothing of the half attempt survives: no page rows, no ledger rows, no uploaded image.
        $this->assertSame([], $this->archive->pages());
        $this->assertCount(0, $conversion->pages()->get());
        $this->assertSame([], $this->uploadedImages());
        $this->assertDirectoryDoesNotExist($this->workspaceRoot.DIRECTORY_SEPARATOR.self::CONTENT);
    }

    public function test_a_retry_after_a_broken_attempt_stores_each_page_once(): void
    {
        $conversion = $this->claimedConversion();

        $this->pipeline(store: $this->failingStore(failOnUpload: 3))->convert($conversion);
        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);

        // The dispatcher would hand it out again; the second attempt must not double the pages.
        $conversion->update(['status' => ConversionStatus::Claimed, 'worker' => 'test', 'claimed_at' => now(), 'heartbeat_at' => now()]);
        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(3, $conversion->pages);
        $this->assertCount(3, $this->archive->pages());
        $this->assertCount(3, $this->uploadedImages());
    }

    public function test_a_source_file_that_is_not_on_the_store_is_given_up_on_at_once(): void
    {
        // The archive has thousands of rows naming files that are no longer on the site. Each one used
        // to cost three FTP attempts and then three conversion attempts; there is nothing to wait for.
        config(['converter.failure.max_attempts' => 3]);
        File::delete($this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, '2023/02/01/07/43/38').DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf');
        $conversion = $this->claimedConversion();

        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(Stage::Missing, $conversion->failure_stage);
        $this->assertSame(1, $conversion->attempts);
        $this->assertStringContainsString('not on the file store', (string) $conversion->failure_reason);

        // Marked failed in the archive, so discovery stops offering it, and nothing was written.
        $this->assertSame([self::CONTENT], $this->archive->failedContents());
        $this->assertSame([], $this->archive->pages());
        $this->assertSame([], $this->archive->convertedContents());
    }

    public function test_a_download_that_failed_for_another_reason_is_still_retried(): void
    {
        // A right that was taken away or a site mid-restore is not a stale row: calling those missing
        // would file a fixable problem under an outcome nothing ever retries.
        $store = new class($this->store) extends LocalFileStore
        {
            public function download(string $remotePath, string $localPath): int
            {
                throw FileStoreException::transient('the data connection dropped');
            }
        };

        $conversion = $this->claimedConversion();

        $this->pipeline(store: $store)->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(Stage::Download, $conversion->failure_stage);
    }

    public function test_a_damaged_pdf_is_marked_failed_in_the_archive_and_not_retried(): void
    {
        config(['converter.failure.max_attempts' => 3]);
        $renderer = new FakePageRenderer(failure: new RenderFailed('the pdf is password protected', exitCode: 7, retryable: false));
        $conversion = $this->claimedConversion();

        $this->pipeline(renderer: $renderer)->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(Stage::Render, $conversion->failure_stage);
        $this->assertStringContainsString('password protected', (string) $conversion->failure_reason);
        $this->assertSame([self::CONTENT], $this->archive->failedContents());
        $this->assertSame([], $this->archive->convertedContents());
    }

    public function test_a_failure_names_the_file_it_was_working_on(): void
    {
        // "pdf2img exited with 2: the file is not a readable PDF" says nothing anybody can act on. The
        // workspace copy is deleted by then, so the path on the file store is the only one still worth
        // having - with it, the PDF can be opened and looked at.
        $renderer = new FakePageRenderer(failure: new RenderFailed('pdf2img exited with 2: the file is not a readable PDF', exitCode: 2, retryable: false));
        $conversion = $this->claimedConversion();

        $this->pipeline(renderer: $renderer)->convert($conversion);

        $reason = (string) $conversion->refresh()->failure_reason;
        $this->assertStringContainsString('not a readable PDF', $reason);
        $this->assertStringContainsString('2023/02/01/07/43/38/'.self::SOURCE_MVD.'.pdf', $reason);
    }

    public function test_a_content_another_worker_holds_is_put_back_without_touching_the_archive(): void
    {
        $this->archive->reserve(self::CONTENT, 'somebody-else');
        $conversion = $this->claimedConversion();

        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(Stage::Reserve, $conversion->failure_stage);

        // Still the other worker's: we must not release a reservation we never held.
        $this->assertSame('somebody-else', $this->archive->ownerOf(self::CONTENT));
    }

    public function test_a_failure_on_the_second_pdf_leaves_the_first_one_convertible_again(): void
    {
        // Two PDFs on one content. The pages of the first are written and uploaded before the second
        // is even downloaded, so if its source row were hidden at that point, the retry would see one
        // PDF instead of two and the content would end up converted with half its pages - silently,
        // because sourceFilesFor() only returns rows that are not deleted.
        $this->addSecondSource();
        $conversion = $this->claimedConversion();

        $this->pipeline(store: $this->failingStore(failOnUpload: 4))->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame([], $this->archive->softDeletedSources());

        $conversion->update(['status' => ConversionStatus::Claimed, 'worker' => 'test', 'claimed_at' => now(), 'heartbeat_at' => now()]);
        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(6, $conversion->pages);
        $this->assertCount(6, $this->archive->pages());
        $this->assertCount(6, $this->uploadedImages());
        $this->assertSame([self::SOURCE_MVD, self::SECOND_MVD], $this->archive->softDeletedSources());
    }

    public function test_a_failure_while_marking_the_content_converted_leaves_the_pdf_findable(): void
    {
        // The crash that used to lose a document: it happened between hiding the PDF and recording the
        // content converted, and the retry then found no source row at all and marked the content
        // failed for good - with the pages of the attempt it was retrying already cleaned up.
        $this->archive = new class extends FakeArchive
        {
            public bool $refuse = true;

            public function markConverted(string $contentId): void
            {
                if ($this->refuse) {
                    $this->refuse = false;

                    throw new RuntimeException('the archive connection dropped');
                }

                parent::markConverted($contentId);
            }
        };
        $this->archive->addContent(self::CONTENT, profileId: 65);
        $this->archive->addSourceFile(self::CONTENT, $this->pdfSource());

        $conversion = $this->claimedConversion();
        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame([], $this->archive->softDeletedSources());
        $this->assertCount(1, $this->archive->sourceFilesFor(self::CONTENT));
        $this->assertSame([], $this->archive->convertedContents());

        $conversion->update(['status' => ConversionStatus::Claimed, 'worker' => 'test', 'claimed_at' => now(), 'heartbeat_at' => now()]);
        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(3, $conversion->pages);
        $this->assertSame([self::CONTENT], $this->archive->convertedContents());
    }

    public function test_a_worker_that_lost_its_claim_stops_instead_of_marking_the_content_converted(): void
    {
        // What the reconciler does to a conversion it believes is dead, done here in the middle of
        // one: the row goes back to pending and the page ledger is emptied. The worker must notice at
        // its next heartbeat and stop - the pages it has written are no longer recorded anywhere, so
        // marking the content converted would leave the archive with a converted content whose pages
        // the next attempt cannot find, and releasing it would take it off whoever holds it now.
        $conversion = $this->claimedConversion();
        $store = $this->reclaimingStore($conversion, onUpload: 2);

        $this->pipeline(store: $store)->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(1, $conversion->attempts);
        $this->assertSame([], $this->archive->convertedContents());
        $this->assertSame([], $this->archive->failedContents());
        $this->assertSame([], $this->archive->softDeletedSources());

        // The rows it wrote before it noticed belong to no ledger. The next attempt finds them in the
        // archive itself and removes them, so the content still ends up with exactly its own pages.
        $this->assertCount(2, $this->archive->pages());

        $this->archive->release(self::CONTENT);
        $conversion->update(['status' => ConversionStatus::Claimed, 'worker' => 'test', 'claimed_at' => now(), 'heartbeat_at' => now()]);
        $this->pipeline()->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertCount(3, $this->archive->pages());
    }

    public function test_a_transient_failure_that_runs_out_of_attempts_does_not_mark_the_content_failed(): void
    {
        // The archive's failed marker takes a content out of discovery for good, so it is only ever
        // written for a verdict about the document. An FTP site that is down is not that: marking it
        // would bury half a million good documents three attempts at a time.
        config(['converter.failure.max_attempts' => 1]);
        $conversion = $this->claimedConversion();

        $this->pipeline(store: $this->failingStore(failOnUpload: 1))->convert($conversion);

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(Stage::Upload, $conversion->failure_stage);
        $this->assertSame([], $this->archive->failedContents());
        $this->assertNull($this->archive->ownerOf(self::CONTENT));
    }

    public function test_the_workspace_is_deleted_when_the_renderer_fails(): void
    {
        $conversion = $this->claimedConversion();

        $this->pipeline(renderer: new FakePageRenderer(failure: new RenderFailed('pdf2img timed out', exitCode: null, retryable: true)))
            ->convert($conversion);

        $this->assertDirectoryDoesNotExist($this->workspaceRoot.DIRECTORY_SEPARATOR.self::CONTENT);
    }

    private function pipeline(?FileStore $store = null, ?PageRenderer $renderer = null): ConvertOneContent
    {
        return new ConvertOneContent(
            $this->archive,
            $store ?? new LocalFileStore($this->store),
            $renderer ?? new FakePageRenderer(pages: 3),
            new FakeThumbnailer,
            new ConversionQueue,
            new ContentWorkspace($this->workspaceRoot, freeSpaceFloorGb: 0),
        );
    }

    /**
     * A second PDF on the same content, with its file where the archive says it is.
     */
    private function addSecondSource(): void
    {
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SECOND_MVD,
            seqPageNo: 2,
            pageNo: '13870611_16_ettelaat_pdf_zamimeh_50.pdf',
            createDateTime: self::CREATED,
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));

        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, '2023/02/01/07/43/38');
        File::put($folder.DIRECTORY_SEPARATOR.self::SECOND_MVD.'.pdf', 'another pdf');
    }

    private function pdfSource(): SourceFile
    {
        return new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: '13870611_16_ettelaat_pdf_zamimeh_49.pdf',
            createDateTime: self::CREATED,
            format: 'Application/pdf',
            ftpSiteId: 1,
        );
    }

    /**
     * A file store that takes the conversion away from its worker on the given upload, the way
     * converters:reconcile does to a claim it believes is dead.
     */
    private function reclaimingStore(Conversion $conversion, int $onUpload): FileStore
    {
        return new class($this->store, $conversion, $onUpload) extends LocalFileStore
        {
            private int $uploads = 0;

            public function __construct(
                string $root,
                private readonly Conversion $conversion,
                private readonly int $onUpload,
            ) {
                parent::__construct($root);
            }

            public function upload(string $localPath, string $remotePath): int
            {
                $bytes = parent::upload($localPath, $remotePath);

                if (++$this->uploads === $this->onUpload) {
                    Conversion::query()->whereKey($this->conversion->getKey())->update([
                        'status' => ConversionStatus::Pending,
                        'worker' => null,
                        'claimed_at' => null,
                        'heartbeat_at' => null,
                        'attempts' => 1,
                    ]);

                    $this->conversion->pages()->delete();
                }

                return $bytes;
            }
        };
    }

    /**
     * A file store that works until the given upload, so a conversion can be broken half way.
     */
    private function failingStore(int $failOnUpload): FileStore
    {
        return new class($this->store, $failOnUpload) extends LocalFileStore
        {
            private int $uploads = 0;

            public function __construct(string $root, private readonly int $failOnUpload)
            {
                parent::__construct($root);
            }

            public function upload(string $localPath, string $remotePath): int
            {
                if (++$this->uploads === $this->failOnUpload) {
                    throw FileStoreException::transient("the connection dropped while storing {$remotePath}");
                }

                return parent::upload($localPath, $remotePath);
            }
        };
    }

    private function claimedConversion(): Conversion
    {
        return Conversion::query()->create([
            'content_id' => self::CONTENT,
            'profile_id' => 65,
            'status' => ConversionStatus::Claimed,
            'worker' => 'test',
            'claimed_at' => now(),
            'heartbeat_at' => now(),
        ]);
    }

    /**
     * @return list<string>
     */
    private function uploadedImages(): array
    {
        $images = [];

        foreach (File::allFiles($this->store) as $file) {
            if ($file->getExtension() === 'jpg') {
                $images[] = $file->getFilename();
            }
        }

        sort($images);

        return $images;
    }
}
