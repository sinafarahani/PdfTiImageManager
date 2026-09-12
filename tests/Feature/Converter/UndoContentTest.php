<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class UndoContentTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '20087dc6-69dd-4225-9c97-58a66dba4b3f';

    private const string SOURCE_MVD = '96c4ad6c-6a91-f111-af43-0050569d366f';

    private const string CREATED = '2026-09-12 20:05:07';

    private string $store;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-undo-'.uniqid();
        File::ensureDirectoryExists($this->store);

        config(['converter.archive.write_mode' => 'on']);

        $this->archive = new FakeArchive;
        $this->archive->addContent(self::CONTENT, profileId: 66);
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: '19050510570000.pdf',
            createDateTime: '2026-08-17 00:02:57',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));

        $this->app->instance(ArchiveGateway::class, $this->archive);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_it_reports_what_it_would_remove_and_changes_nothing_without_confirm(): void
    {
        $this->convertedContent(pages: 3);

        $this->artisan('converters:undo', ['contentId' => self::CONTENT])
            ->expectsOutputToContain('image page rows in the archive')
            ->expectsOutputToContain('Nothing was changed')
            ->assertSuccessful();

        $this->assertCount(3, $this->archive->pages());
        $this->assertSame([self::SOURCE_MVD], $this->archive->softDeletedSources());
        $this->assertCount(3, $this->uploadedImages());
    }

    public function test_it_removes_the_page_rows_the_images_and_the_verdict(): void
    {
        $conversion = $this->convertedContent(pages: 3);

        $this->artisan('converters:undo', ['contentId' => self::CONTENT, '--confirm' => true])->assertSuccessful();

        // Out of the archive: the page rows, and with them the thumbnails and images that cascade.
        $this->assertSame([], $this->archive->pages());

        // The original PDF is on show again, and the content is no longer converted.
        $this->assertSame([], $this->archive->softDeletedSources());
        $this->assertSame([self::SOURCE_MVD], $this->archive->restoredSources());
        $this->assertSame([self::CONTENT], $this->archive->undoneContents());
        $this->assertSame([], $this->archive->convertedContents());

        // The images are gone from the file store, and so is the panel's record of them.
        $this->assertSame([], $this->uploadedImages());
        $this->assertCount(0, $conversion->pages()->get());

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Cancelled, $conversion->status);
        $this->assertSame(0, $conversion->attempts);
    }

    public function test_requeue_puts_the_content_back_in_the_queue(): void
    {
        $conversion = $this->convertedContent(pages: 1);

        $this->artisan('converters:undo', [
            'contentId' => self::CONTENT,
            '--confirm' => true,
            '--requeue' => true,
        ])->assertSuccessful();

        $conversion->refresh();
        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertNull($conversion->finished_at);
    }

    public function test_it_refuses_while_a_worker_is_converting_the_content(): void
    {
        $conversion = $this->convertedContent(pages: 2);
        $conversion->forceFill([
            'status' => ConversionStatus::Claimed,
            'worker' => 'worker-1',
            'claimed_at' => now(),
            'heartbeat_at' => now(),
        ])->save();

        $this->artisan('converters:undo', ['contentId' => self::CONTENT, '--confirm' => true])
            ->expectsOutputToContain('A worker is converting this content right now.')
            ->assertFailed();

        $this->assertCount(2, $this->archive->pages());
    }

    public function test_it_refuses_to_undo_while_the_archive_is_in_dry_run_mode(): void
    {
        // Undoing writes to the archive, so it cannot work with the write gate closed - and saying so
        // is better than deleting the images and then failing on the rows.
        config(['converter.archive.write_mode' => 'off']);
        $this->convertedContent(pages: 2);

        $this->artisan('converters:undo', ['contentId' => self::CONTENT, '--confirm' => true])
            ->expectsOutputToContain('CONVERTER_WRITE_MODE is not "on"')
            ->assertFailed();

        $this->assertCount(2, $this->archive->pages());
        $this->assertCount(2, $this->uploadedImages());
    }

    public function test_a_content_that_was_never_converted_is_left_alone(): void
    {
        $this->artisan('converters:undo', ['contentId' => self::CONTENT, '--confirm' => true])
            ->expectsOutputToContain('There is nothing to undo for this content.')
            ->assertSuccessful();
    }

    /**
     * A content as it is after a conversion: page rows in the archive, their images on the file store,
     * the source PDF hidden, and the panel's ledger knowing all of it.
     */
    private function convertedContent(int $pages): Conversion
    {
        $conversion = Conversion::query()->create([
            'content_id' => self::CONTENT,
            'profile_id' => 66,
            'status' => ConversionStatus::Done,
            'pages' => $pages,
            'finished_at' => now(),
        ]);

        for ($sequence = 1; $sequence <= $pages; $sequence++) {
            $mvdId = $this->archive->insertPage(new PageInsert(
                contentId: self::CONTENT,
                seqPageNo: $sequence,
                createDateTime: self::CREATED,
                format: 'Image/jpg',
                ftpSiteId: 1,
                thumbnail: 'thumbnail bytes',
            ));

            $remotePath = SourceFile::folderFor(self::CREATED).'/'.$mvdId.'.jpg';
            $image = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $remotePath);
            File::ensureDirectoryExists(dirname($image));
            File::put($image, 'page image');

            $conversion->pages()->create([
                'seq' => $sequence,
                'mvd_id' => $mvdId,
                'remote_path' => $remotePath,
                'bytes' => 10,
                'uploaded' => true,
            ]);
        }

        $this->archive->softDeleteSource(self::SOURCE_MVD);

        return $conversion;
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
