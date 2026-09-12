<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Render\FakePageRenderer;
use App\Actions\Converter\Render\FakeThumbnailer;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\Thumbnailer;
use App\Models\Conversion;
use App\Models\ConversionPage;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/**
 * converters:try is the command the operator points at production before the panel is ever started,
 * so the promise it makes is the only thing that matters here: a run without --write reads the live
 * archive, really downloads and renders the PDF, and changes nothing at all - not one archive row,
 * not one file on the site, and not one row of the panel's own queue.
 */
class TryContentTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '1c8f16cf-4635-42f8-971d-451b8a1b1ae1';

    private const string SOURCE_MVD = 'a47e40c9-e6a1-ed11-96cd-005056baa2b4';

    private const string CREATED = '2023-02-01 07:43:38';

    /**
     * The moment the run happens. MVDContent.CreateDateTime and the FTP folder of every page are both
     * derived from it, so freezing it makes the reported remote paths exact.
     */
    private const string NOW = '2026-09-12 10:11:12';

    private string $store;

    private string $workspaceRoot;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-try-store-'.uniqid();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-try-work-'.uniqid();
        File::ensureDirectoryExists($this->store);

        config([
            'converter.workspace.root' => $this->workspaceRoot,
            'converter.workspace.free_space_floor_gb' => 0,
            'converter.archive.write_mode' => 'off',
        ]);

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

        // The source PDF, where the archive says it is: <CreateDateTime folder>/<its own id>.pdf
        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, '2023/02/01/07/43/38');
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf', 'a pdf');

        $this->useArchive($this->archive);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));
        $this->app->instance(PageRenderer::class, new FakePageRenderer(pages: 3));
        $this->app->instance(Thumbnailer::class, new FakeThumbnailer);

        $this->travelTo(CarbonImmutable::parse(self::NOW));
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        File::deleteDirectory($this->workspaceRoot);

        parent::tearDown();
    }

    public function test_a_dry_run_reports_the_pages_and_their_remote_paths_and_writes_nothing(): void
    {
        $this->artisan('converters:try', ['contentId' => self::CONTENT])
            ->expectsOutputToContain('DOI/2023/02/01/07/43/38/'.self::SOURCE_MVD.'.pdf')
            ->expectsOutputToContain('pdf2img produced 3 page image(s)')
            ->expectsOutputToContain('DOI/2026/09/12/10/11/12/dryrun-0001-0000-0000-000000000000.jpg')
            ->expectsOutputToContain('DOI/2026/09/12/10/11/12/dryrun-0003-0000-0000-000000000000.jpg')
            ->expectsOutputToContain('CreateDateTime '.self::NOW)
            ->expectsOutputToContain('3 page(s) would be written')
            ->assertSuccessful();

        // The archive was only read. Not a page row, not a reservation, not a marker, not a source
        // row hidden - the whole point of the command.
        $this->assertSame([], $this->archive->pages());
        $this->assertSame([], $this->archive->convertedContents());
        $this->assertSame([], $this->archive->failedContents());
        $this->assertSame([], $this->archive->softDeletedSources());
        $this->assertNull($this->archive->ownerOf(self::CONTENT));

        // Nor was anything uploaded, and the panel's queue never heard of the content.
        $this->assertSame([], $this->uploadedImages());
        $this->assertSame(0, Conversion::query()->count());
        $this->assertSame(0, ConversionPage::query()->count());
    }

    public function test_a_dry_run_leaves_an_existing_queue_row_exactly_as_it_was(): void
    {
        // A content that failed once already: the dry run must not spend its second attempt, nor turn
        // the row into a "converted" one for a conversion that never happened.
        $queued = Conversion::query()->create([
            'content_id' => self::CONTENT,
            'profile_id' => 65,
            'status' => ConversionStatus::Pending,
            'attempts' => 1,
            'failure_reason' => 'the connection dropped while storing page 2',
        ]);

        $this->artisan('converters:try', ['contentId' => self::CONTENT])->assertSuccessful();

        $queued->refresh();
        $this->assertSame(ConversionStatus::Pending, $queued->status);
        $this->assertSame(1, $queued->attempts);
        $this->assertSame('the connection dropped while storing page 2', $queued->failure_reason);
        $this->assertNull($queued->worker);
        $this->assertNull($queued->claimed_at);
        $this->assertNull($queued->pages);
        $this->assertSame(1, Conversion::query()->count());
        $this->assertSame(0, ConversionPage::query()->count());
    }

    public function test_a_dry_run_says_plainly_that_the_content_already_has_pages_and_leaves_them_standing(): void
    {
        // One page row from an earlier conversion, written straight into the archive.
        $this->archive->insertPage(new PageInsert(
            contentId: self::CONTENT,
            seqPageNo: 1,
            createDateTime: self::CREATED,
            format: 'Image/jpg',
            ftpSiteId: 1,
            thumbnail: 'thumb-bytes',
        ));

        $this->artisan('converters:try', ['contentId' => self::CONTENT])
            ->expectsOutputToContain('already has 1 image page row(s)')
            ->expectsOutputToContain('writes 3 new page(s) in their place')
            ->assertSuccessful();

        // The run would have deleted that row first; it was recorded, not performed.
        $this->assertCount(1, $this->archive->pages());
    }

    public function test_a_dry_run_of_a_content_without_a_pdf_fails_and_still_writes_nothing(): void
    {
        $archive = new FakeArchive;
        $archive->addContent(self::CONTENT, profileId: 65);
        $this->useArchive($archive);

        $this->artisan('converters:try', ['contentId' => self::CONTENT])
            ->expectsOutputToContain('0 PDF source row(s)')
            ->expectsOutputToContain('did not reach the end')
            ->assertFailed();

        // The pipeline would have marked this content failed in the archive; a dry run may not.
        $this->assertSame([], $archive->failedContents());
        $this->assertSame(0, Conversion::query()->count());
    }

    public function test_a_content_a_worker_is_converting_right_now_is_left_alone(): void
    {
        $claimed = Conversion::query()->create([
            'content_id' => self::CONTENT,
            'status' => ConversionStatus::Claimed,
            'worker' => 'converter@build-pc:4212',
            'claimed_at' => now(),
            'heartbeat_at' => now(),
        ]);

        $this->artisan('converters:try', ['contentId' => self::CONTENT])
            ->expectsOutputToContain('being converted right now by converter@build-pc:4212')
            ->assertFailed();

        $this->assertSame('converter@build-pc:4212', $claimed->refresh()->worker);
        $this->assertSame([], $this->archive->pages());
    }

    public function test_write_converts_the_content_for_real(): void
    {
        config(['converter.archive.write_mode' => 'on']);

        $this->artisan('converters:try', ['contentId' => self::CONTENT, '--write' => true])
            ->expectsOutputToContain('will be changed')
            ->expectsOutputToContain('3 page(s) were written')
            ->assertSuccessful();

        $this->assertCount(3, $this->archive->pages());
        $this->assertSame([self::CONTENT], $this->archive->convertedContents());
        $this->assertSame([self::SOURCE_MVD], $this->archive->softDeletedSources());
        $this->assertCount(3, $this->uploadedImages());

        $conversion = Conversion::query()->where('content_id', self::CONTENT)->sole();
        $this->assertSame(ConversionStatus::Done, $conversion->status);
        $this->assertSame(3, $conversion->pages);
        $this->assertSame(3, $conversion->pages()->uploaded()->count());
    }

    public function test_write_is_refused_while_the_archive_is_in_dry_run_mode(): void
    {
        config(['converter.archive.write_mode' => 'off']);

        $this->artisan('converters:try', ['contentId' => self::CONTENT, '--write' => true])
            ->expectsOutputToContain('CONVERTER_WRITE_MODE=on')
            ->assertFailed();

        // Refused before anything at all was built, so there is nothing to undo.
        $this->assertSame([], $this->archive->pages());
        $this->assertSame([], $this->archive->convertedContents());
        $this->assertSame([], $this->uploadedImages());
        $this->assertSame(0, Conversion::query()->count());
    }

    private function useArchive(FakeArchive $archive): void
    {
        $this->archive = $archive;
        $this->app->instance(ArchiveGateway::class, $archive);
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
