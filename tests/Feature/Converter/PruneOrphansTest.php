<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use App\Models\PurgedSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PruneOrphansTest extends TestCase
{
    use RefreshDatabase;

    private const CONTENT = 'A1B2C3D4-0000-0000-0000-000000000001';

    private const SOURCE_MVD = '8E3C2A40-0000-0000-0000-000000000001';

    private const FOLDER = '2023/02/01/07/43/38';

    private FakeArchive $archive;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'orphan-store-'.uniqid();
        File::ensureDirectoryExists($this->store);

        $this->archive = new FakeArchive;
        $this->archive->addContent(self::CONTENT, profileId: 12);
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: 'the-original.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));

        $this->app->instance(ArchiveGateway::class, $this->archive);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));

        config(['converter.archive.write_mode' => 'on']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_it_removes_a_hidden_source_row_whose_file_was_deleted_long_ago(): void
    {
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $conversion = $this->conversion(ConversionStatus::Done);

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('1 orphaned source row(s) removed')
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());

        // Recorded as an orphan, not as a purge: nothing was destroyed here, the file had gone long
        // before. Anyone reading this table after an incident has to be able to tell them apart.
        $record = PurgedSource::query()->sole();
        $this->assertSame(PurgedSource::ORPHANED, $record->reason);
        $this->assertSame(self::FOLDER.'/'.self::SOURCE_MVD.'.pdf', $record->remote_path);
        $this->assertSame('the-original.pdf', $record->original_name);
        $this->assertSame($conversion->id, $record->conversion_id);
        $this->assertTrue($record->row_deleted);
        $this->assertTrue($record->file_deleted);
        $this->assertNull($record->bytes);
    }

    public function test_a_hidden_source_whose_file_is_still_there_is_left_alone(): void
    {
        $this->putSourceFile();
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('0 orphaned source row(s) removed')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_a_source_still_on_show_is_counted_and_never_deleted(): void
    {
        // The other population: the archive is still offering that document, so a missing file there
        // is a fault to look into rather than a row to tidy away. It is where the skipped profiles
        // sit - their files went but nothing ever flagged their rows.
        $this->conversion(ConversionStatus::Cancelled, Stage::Skipped);

        $this->artisan('converters:prune-orphans')
            ->expectsOutputToContain('sources missing their file but NOT flagged deleted')
            ->expectsOutputToContain('where the skipped profiles sit')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_it_refuses_to_run_at_all_when_the_file_store_cannot_be_read(): void
    {
        // The one way this command could do real damage: a store that is down says "not there" about
        // every file on it, and believing that would strip the archive of every source row it reached.
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store.'-not-mounted'));

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('The file store could not be read')
            ->expectsOutputToContain('would take the archive apart')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_a_file_the_store_will_not_answer_for_is_left_alone(): void
    {
        // "The server did not answer" is not "the file is gone". Only a store that answered and said
        // so counts as evidence.
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);

        $this->app->instance(FileStore::class, new class($this->store) extends LocalFileStore
        {
            public function size(string $remotePath): ?int
            {
                throw FileStoreException::transient('the data connection dropped');
            }
        });

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('sources the store would not answer for')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_check_zero_examines_every_content(): void
    {
        // 500 of 551,866 is a sample, and the run that matters is the one that looked at all of them.
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);

        foreach (range(1, 4) as $n) {
            $contentId = "E0E0E0E0-0000-0000-0000-00000000000{$n}";
            $this->archive->addContent($contentId, profileId: 12);
            Conversion::query()->create([
                'content_id' => $contentId,
                'profile_id' => 12,
                'status' => ConversionStatus::Done,
                'pages' => 1,
                'finished_at' => now(),
            ]);
        }

        $this->artisan('converters:prune-orphans', ['--check' => 1])
            ->expectsOutputToContain('--check raises this')
            ->assertSuccessful();

        $this->artisan('converters:prune-orphans', ['--check' => 0])
            ->expectsOutputToContain('5 of 5')
            ->doesntExpectOutputToContain('--check raises this')
            ->assertSuccessful();
    }

    public function test_it_does_nothing_without_confirm(): void
    {
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);

        $this->artisan('converters:prune-orphans')
            ->expectsOutputToContain('1 row(s) would be removed. Their files are already gone.')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_it_refuses_when_the_archive_is_not_accepting_writes(): void
    {
        config(['converter.archive.write_mode' => 'off']);
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Done);

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('not accepting writes')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_it_looks_at_contents_whatever_became_of_them(): void
    {
        // Not only the converted ones. A skipped profile's content was never converted and a content
        // whose source went missing was never converted either; they leave the same orphan behind.
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->conversion(ConversionStatus::Failed, Stage::Missing);

        $this->artisan('converters:prune-orphans', ['--confirm' => true])
            ->expectsOutputToContain('1 orphaned source row(s) removed')
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());
    }

    private function conversion(ConversionStatus $status, ?Stage $stage = null): Conversion
    {
        return Conversion::query()->create([
            'content_id' => self::CONTENT,
            'profile_id' => 12,
            'status' => $status,
            'failure_stage' => $stage,
            'pages' => $status === ConversionStatus::Done ? 1 : null,
            'finished_at' => now(),
        ]);
    }

    private function putSourceFile(): void
    {
        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::FOLDER);
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf', 'a pdf');
    }
}
