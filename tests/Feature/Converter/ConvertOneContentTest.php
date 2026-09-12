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
use Tests\TestCase;

class ConvertOneContentTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '1c8f16cf-4635-42f8-971d-451b8a1b1ae1';

    private const string SOURCE_MVD = 'a47e40c9-e6a1-ed11-96cd-005056baa2b4';

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
