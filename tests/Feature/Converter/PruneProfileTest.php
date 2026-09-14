<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Models\PurgedSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PruneProfileTest extends TestCase
{
    use RefreshDatabase;

    private const DEAD_CONTENT = 'A1B2C3D4-0000-0000-0000-000000000001';

    private const DEAD_MVD = '10000000-0000-0000-0000-000000000001';

    private const LIVE_CONTENT = 'B1B2C3D4-0000-0000-0000-000000000002';

    private const LIVE_MVD = '20000000-0000-0000-0000-000000000002';

    private const FOLDER = '2023/02/01/07/43/38';

    private FakeArchive $archive;

    private string $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'profile-store-'.uniqid();
        File::ensureDirectoryExists($this->store);

        $this->archive = new FakeArchive;

        // Profile 65 is dead and its file was deleted long ago. Its row was never flagged, because
        // the pipeline never converted it.
        $this->archive->addContent(self::DEAD_CONTENT, profileId: 65);
        $this->archive->addSourceFile(self::DEAD_CONTENT, $this->source(self::DEAD_MVD));

        // Profile 12 is live, and its file is gone too. Nothing here may touch it.
        $this->archive->addContent(self::LIVE_CONTENT, profileId: 12);
        $this->archive->addSourceFile(self::LIVE_CONTENT, $this->source(self::LIVE_MVD));

        $this->app->instance(ArchiveGateway::class, $this->archive);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));

        config(['converter.archive.write_mode' => 'on', 'converter.skip_profiles' => [65]]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_it_removes_a_dead_profiles_rows_even_though_they_were_never_flagged_deleted(): void
    {
        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])
            ->expectsOutputToContain('1 source row(s) of profile 65 removed')
            ->assertSuccessful();

        $this->assertSame([self::DEAD_MVD], $this->archive->hardDeletedSources());

        $record = PurgedSource::query()->sole();
        $this->assertSame(PurgedSource::ORPHANED, $record->reason);
        $this->assertSame(self::DEAD_CONTENT, $record->content_id);
        $this->assertSame(self::FOLDER.'/'.self::DEAD_MVD.'.pdf', $record->remote_path);
        $this->assertTrue($record->row_deleted);
    }

    public function test_another_profiles_rows_are_never_touched_even_though_their_files_are_gone_too(): void
    {
        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])->assertSuccessful();

        $this->assertNotContains(self::LIVE_MVD, $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->where('mvd_id', self::LIVE_MVD)->count());
    }

    public function test_a_profile_that_is_not_declared_dead_is_refused(): void
    {
        // The whole safety of this command rests on the profile being one nobody converts any more.
        $this->artisan('converters:prune-profile', ['--profile' => 12, '--confirm' => true])
            ->expectsOutputToContain('not in CONVERTER_SKIP_PROFILES')
            ->expectsOutputToContain('Currently named there: 65')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_the_profile_has_to_be_named(): void
    {
        $this->artisan('converters:prune-profile', ['--confirm' => true])
            ->expectsOutputToContain('Name the profile to strip')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_a_row_whose_file_is_still_there_is_left_alone_and_said_out_loud(): void
    {
        // A dead profile with files still on the store is not the situation this is for, and quietly
        // deleting those rows would take documents that still exist out of the archive.
        $this->putFile(self::DEAD_MVD);

        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])
            ->expectsOutputToContain('files are still on the store')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_it_refuses_to_run_when_the_file_store_cannot_be_read(): void
    {
        $this->app->instance(FileStore::class, new LocalFileStore($this->store.'-not-mounted'));

        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])
            ->expectsOutputToContain('would take the archive apart')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_a_file_the_store_will_not_answer_for_is_left_alone(): void
    {
        $this->app->instance(FileStore::class, new class($this->store) extends LocalFileStore
        {
            public function size(string $remotePath): ?int
            {
                throw FileStoreException::transient('the data connection dropped');
            }
        });

        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])
            ->expectsOutputToContain('rows the store would not answer for')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_it_refuses_when_the_archive_is_not_accepting_writes(): void
    {
        config(['converter.archive.write_mode' => 'off']);

        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])
            ->expectsOutputToContain('not accepting writes')
            ->assertFailed();

        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_it_does_nothing_without_confirm(): void
    {
        $this->artisan('converters:prune-profile', ['--profile' => 65])
            ->expectsOutputToContain('1 row(s) would be removed')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_a_finished_walk_remembers_nothing_so_the_next_one_starts_over(): void
    {
        $this->artisan('converters:prune-profile', ['--profile' => 65, '--confirm' => true])->assertSuccessful();

        $this->assertNull(DB::table('conversion_watermarks')->where('name', 'prune-profile-65')->value('cursor'));
    }

    private function source(string $mvdId): SourceFile
    {
        return new SourceFile(
            mvdId: $mvdId,
            seqPageNo: 1,
            pageNo: 'the-original.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        );
    }

    private function putFile(string $mvdId): void
    {
        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::FOLDER);
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.$mvdId.'.pdf', 'a pdf');
    }
}
