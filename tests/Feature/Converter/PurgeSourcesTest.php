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
use App\Models\ConversionSource;
use App\Models\PurgedSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

class PurgeSourcesTest extends TestCase
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

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'purge-store-'.uniqid();
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

        $this->putSourceFile();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_it_does_nothing_at_all_without_confirm(): void
    {
        $this->convertedContent();

        $this->artisan('converters:purge-sources')
            ->expectsOutputToContain('There is no way back from this')
            ->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_a_rehearsal_that_would_destroy_nothing_says_why_and_what_to_do(): void
    {
        // The first run against a queue converted before any of this existed refuses every content
        // for the same reason. Reporting "0 would be destroyed" and nothing else leaves the operator
        // with no way to tell a safe refusal from a broken command.
        $this->convertedContent(recordTheSource: false);

        $this->artisan('converters:purge-sources')
            ->expectsOutputToContain('Nothing would be destroyed.')
            ->expectsOutputToContain('1 content(s) were left alone:')
            ->expectsOutputToContain('converted before the panel recorded which source row it hid')
            ->expectsOutputToContain(self::CONTENT)
            ->expectsOutputToContain('--unrecorded allows them')
            ->assertSuccessful();
    }

    public function test_the_reasons_are_grouped_rather_than_repeated_once_per_content(): void
    {
        foreach (range(1, 3) as $n) {
            $contentId = "B0B0B0B0-0000-0000-0000-00000000000{$n}";
            $this->archive->addContent($contentId, profileId: 12);
            Conversion::query()->create([
                'content_id' => $contentId,
                'profile_id' => 12,
                'status' => ConversionStatus::Done,
                'pages' => 1,
                'finished_at' => now(),
            ]);
        }

        $this->artisan('converters:purge-sources')
            ->expectsOutputToContain('3 content(s) were left alone:')
            ->expectsOutputToContain('the archive has no hidden PDF row for this content')
            ->assertSuccessful();
    }

    public function test_the_archive_walk_destroys_a_source_the_old_pipeline_converted(): void
    {
        // Nothing in the panel's tables knows this content exists - the queue only ever held contents
        // that needed converting, so the millions the C# app converted are not in it.
        $this->archive->softDeleteSource(self::SOURCE_MVD);
        $this->archive->insertPage(new PageInsert(
            contentId: self::CONTENT, seqPageNo: 1, createDateTime: '2026-09-14 10:00:00',
            format: 'Image/jpg', ftpSiteId: 1, thumbnail: 'x',
        ));

        $this->assertSame(0, Conversion::query()->count());

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('No converted content has a source PDF left to delete.')
            ->assertSuccessful();

        $this->artisan('converters:purge-sources', ['--archive' => true, '--confirm' => true])
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());
        $this->assertFileDoesNotExist($this->sourcePath());
        $this->assertSame(PurgedSource::PURGED, PurgedSource::query()->sole()->reason);
    }

    public function test_the_archive_walk_refuses_a_content_with_no_page_images_on_show(): void
    {
        // Without a ledger this is the only witness left that the content was ever converted. A
        // hidden source with no pages is the state the old pipeline left 10,601 contents in, and its
        // PDF is the whole document.
        $this->archive->softDeleteSource(self::SOURCE_MVD);

        $this->artisan('converters:purge-sources', ['--archive' => true, '--confirm' => true])
            ->expectsOutputToContain('the archive has no page images on show for this content')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertFileExists($this->sourcePath());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_the_archive_walk_carries_on_where_it_stopped(): void
    {
        foreach (range(1, 4) as $n) {
            $contentId = "D0D0D0D0-0000-0000-0000-00000000000{$n}";
            $mvdId = "A000000{$n}-0000-0000-0000-000000000000";
            $this->archive->addContent($contentId, profileId: 12);
            $this->archive->addSourceFile($contentId, new SourceFile(
                mvdId: $mvdId, seqPageNo: 1, pageNo: 'old.pdf',
                createDateTime: '2019-03-03 03:03:03', format: 'Application/pdf', ftpSiteId: 1,
            ));
            $this->archive->softDeleteSource($mvdId);
            $this->archive->insertPage(new PageInsert(
                contentId: $contentId, seqPageNo: 1, createDateTime: '2026-09-14 10:00:00',
                format: 'Image/jpg', ftpSiteId: 1, thumbnail: 'x',
            ));
        }

        $this->artisan('converters:purge-sources', ['--archive' => true, '--limit' => 2, '--confirm' => true])
            ->assertSuccessful();

        $this->assertCount(2, $this->archive->hardDeletedSources());
        $this->assertSame('A0000002-0000-0000-0000-000000000000', DB::table('conversion_watermarks')->where('name', 'purge-sources')->value('cursor'));

        $this->artisan('converters:purge-sources', ['--archive' => true, '--confirm' => true])->assertSuccessful();

        $this->assertCount(4, $this->archive->hardDeletedSources());
    }

    public function test_it_destroys_the_file_and_the_row_and_writes_down_what_it_destroyed(): void
    {
        $conversion = $this->convertedContent();

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('1 archive row(s) destroyed')
            ->assertSuccessful();

        $this->assertFileDoesNotExist($this->sourcePath());
        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());

        // The record is the only thing left that says the original existed, so it has to carry
        // everything that is now unrecoverable: which file, under what name, from which folder.
        $record = PurgedSource::query()->sole();
        $this->assertSame(self::CONTENT, $record->content_id);
        $this->assertSame(self::SOURCE_MVD, $record->mvd_id);
        $this->assertSame($conversion->id, $record->conversion_id);
        $this->assertSame(self::FOLDER.'/'.self::SOURCE_MVD.'.pdf', $record->remote_path);
        $this->assertSame('the-original.pdf', $record->original_name);
        $this->assertSame('2023-02-01 07:43:38', $record->create_date_time);
        $this->assertTrue($record->row_deleted);
        $this->assertTrue($record->file_deleted);
        $this->assertSame(5, $record->bytes);
    }

    public function test_it_refuses_a_content_the_archive_has_no_pages_for(): void
    {
        // The check standing between a purge and a destroyed document. A done conversion whose pages
        // are not in the archive is one of the states the old pipeline left behind, and its source
        // PDF is the only copy of it that exists.
        $this->convertedContent(withPagesInTheArchive: false);

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('fewer page images are on show in the archive than the conversion recorded')
            ->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_it_leaves_a_source_that_is_still_on_show_alone(): void
    {
        // Deleted = 1 is the archive's record that the pages took over from the source. A row still
        // on show belongs to a content whose pages are not there yet, whatever our ledger says.
        $this->convertedContent(hideTheSource: false);

        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_a_conversion_that_is_not_finished_is_never_touched(): void
    {
        foreach ([ConversionStatus::Pending, ConversionStatus::Claimed, ConversionStatus::Failed] as $status) {
            PurgedSource::query()->delete();
            Conversion::query()->delete();
            $this->convertedContent()->update(['status' => $status]);

            $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

            $this->assertFileExists($this->sourcePath());
            $this->assertSame([], $this->archive->hardDeletedSources());
        }
    }

    public function test_a_content_requeued_after_its_chunk_was_selected_is_left_alone(): void
    {
        // The run walks tens of thousands of contents while the converters keep going. A content that
        // was finished when its chunk was read can be put back in the queue and claimed before the
        // run reaches it, and its source PDF is what that worker converts from.
        $conversion = $this->convertedContent();

        // The window itself: the row is Done when the chunk reads it, and a worker has it by the
        // time the run reaches that content.
        $taken = false;
        Conversion::retrieved(function (Conversion $model) use (&$taken, $conversion): void {
            if (! $taken && $model->getKey() === $conversion->id) {
                $taken = true;

                Conversion::query()->whereKey($conversion->id)->update([
                    'status' => ConversionStatus::Claimed->value,
                    'worker' => 'worker-1',
                ]);
            }
        });

        try {
            $this->artisan('converters:purge-sources', ['--confirm' => true])
                ->expectsOutputToContain('no longer finished')
                ->assertSuccessful();
        } finally {
            Conversion::flushEventListeners();
        }

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->count());
    }

    public function test_a_content_converted_with_no_pages_recorded_is_never_touched(): void
    {
        $this->convertedContent()->update(['pages' => 0]);

        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
    }

    public function test_it_does_not_come_back_for_a_content_it_has_already_purged(): void
    {
        $this->convertedContent();
        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('No converted content has a source PDF left to delete.')
            ->assertSuccessful();

        $this->assertSame(1, PurgedSource::query()->count());
    }

    public function test_a_file_that_survived_an_earlier_run_is_finished_on_the_next_one(): void
    {
        // The row goes first on purpose, so an interruption leaves the recoverable half: the record
        // holds the path and the file is still there to be deleted. That is the state reproduced
        // here - the archive row really is gone, and only the file is left.
        $this->convertedContent();
        $this->archive->hardDeleteSource(self::SOURCE_MVD);
        PurgedSource::query()->create([
            'content_id' => self::CONTENT,
            'mvd_id' => self::SOURCE_MVD,
            'remote_path' => self::FOLDER.'/'.self::SOURCE_MVD.'.pdf',
            'row_deleted' => true,
            'file_deleted' => false,
        ]);

        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        $this->assertFileDoesNotExist($this->sourcePath());
        $this->assertTrue(PurgedSource::query()->sole()->file_deleted);
    }

    public function test_one_content_can_be_named_on_its_own(): void
    {
        $this->convertedContent();
        $other = Conversion::query()->create([
            'content_id' => 'FFFFFFFF-0000-0000-0000-00000000000F',
            'profile_id' => 12,
            'status' => ConversionStatus::Done,
            'pages' => 3,
            'finished_at' => now(),
        ]);

        $this->artisan('converters:purge-sources', ['--content' => [self::CONTENT], '--confirm' => true])
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->where('content_id', $other->content_id)->count());
    }

    /**
     * A content this panel converted: its source hidden and recorded as ours, and its page in the
     * archive - the state the purge is designed for.
     */
    private function convertedContent(
        bool $withPagesInTheArchive = true,
        bool $hideTheSource = true,
        bool $recordTheSource = true,
        int $pages = 1,
    ): Conversion {
        if ($hideTheSource) {
            $this->archive->softDeleteSource(self::SOURCE_MVD);
        }

        $conversion = Conversion::query()->create([
            'content_id' => self::CONTENT,
            'profile_id' => 12,
            'status' => ConversionStatus::Done,
            'pages' => $pages,
            'finished_at' => now(),
        ]);

        if ($withPagesInTheArchive) {
            foreach (range(1, $pages) as $seq) {
                $mvdId = $this->archive->insertPage(new PageInsert(
                    contentId: self::CONTENT,
                    seqPageNo: $seq,
                    createDateTime: '2026-09-14 10:00:00',
                    format: 'Image/jpg',
                    ftpSiteId: 1,
                    thumbnail: 'x',
                ));

                $conversion->pages()->create(['seq' => $seq, 'mvd_id' => $mvdId, 'uploaded' => true]);
            }
        }

        if ($recordTheSource) {
            ConversionSource::query()->create([
                'conversion_id' => $conversion->id,
                'content_id' => self::CONTENT,
                'mvd_id' => self::SOURCE_MVD,
            ]);
        }

        return $conversion;
    }

    public function test_it_will_not_touch_a_pdf_the_panel_did_not_hide_itself(): void
    {
        // The worst thing this command could do. A content can carry PDF rows an archive user deleted
        // in the viewer years ago: never rendered, no pages anywhere, and recoverable by unsetting one
        // flag. hiddenSourcesFor() returns those alongside ours and cannot tell them apart.
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: 'DEADBEEF-0000-0000-0000-00000000000B',
            seqPageNo: 2,
            pageNo: 'an-older-revision.pdf',
            createDateTime: '2019-05-05 05:05:05',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));
        $this->archive->softDeleteSource('DEADBEEF-0000-0000-0000-00000000000B');

        $this->convertedContent();

        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        // Ours went; the older revision is untouched and still restorable.
        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());
        $this->assertSame(0, PurgedSource::query()->where('mvd_id', 'DEADBEEF-0000-0000-0000-00000000000B')->count());
    }

    public function test_a_content_converted_before_the_panel_recorded_its_sources_is_refused(): void
    {
        $this->convertedContent(recordTheSource: false);

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('converted before the panel recorded which source row it hid')
            ->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_unrecorded_allows_it_only_when_there_is_one_hidden_pdf_to_be_wrong_about(): void
    {
        // With exactly one hidden PDF row it is provably the one the conversion hid, because a
        // conversion always hides its own.
        $this->convertedContent(recordTheSource: false);

        $this->artisan('converters:purge-sources', ['--unrecorded' => true, '--confirm' => true])
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD], $this->archive->hardDeletedSources());
    }

    public function test_all_hidden_takes_every_hidden_pdf_of_the_content(): void
    {
        // The pipeline converts every PDF a content has on show, so several hidden rows are probably
        // all its sources. Probably is not provably, which is why it takes a flag of its own.
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: 'DEADBEEF-0000-0000-0000-00000000000B',
            seqPageNo: 2,
            pageNo: 'the-second-pdf.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));
        $this->archive->softDeleteSource('DEADBEEF-0000-0000-0000-00000000000B');
        $this->convertedContent(recordTheSource: false);

        $this->artisan('converters:purge-sources', ['--unrecorded' => true, '--all-hidden' => true, '--confirm' => true])
            ->expectsOutputToContain('2 archive row(s) destroyed')
            ->assertSuccessful();

        $this->assertSame([self::SOURCE_MVD, 'DEADBEEF-0000-0000-0000-00000000000B'], $this->archive->hardDeletedSources());
        $this->assertSame(2, PurgedSource::query()->count());
    }

    public function test_a_rehearsal_reports_the_whole_queue_and_not_just_what_it_checked(): void
    {
        // The first real run reported "500 would be destroyed" because 500 was the batch size, and
        // read as though that were the whole archive. The total is the number that matters.
        // Oldest first, so the sample of 2 reaches the one that is ready.
        $this->convertedContent()->update(['finished_at' => now()->subDay()]);

        foreach (range(1, 4) as $n) {
            $contentId = "C0C0C0C0-0000-0000-0000-00000000000{$n}";
            $this->archive->addContent($contentId, profileId: 12);
            Conversion::query()->create([
                'content_id' => $contentId,
                'profile_id' => 12,
                'status' => ConversionStatus::Done,
                'pages' => 1,
                'finished_at' => now(),
            ]);
        }

        $this->artisan('converters:purge-sources', ['--check' => 2])
            ->expectsOutputToContain('converted contents whose source is still there')
            ->expectsOutputToContain('--check raises this')
            ->expectsOutputToContain('Only 2 were checked')
            ->assertSuccessful();
    }

    public function test_unrecorded_still_refuses_when_there_are_two_hidden_pdfs(): void
    {
        $this->archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: 'DEADBEEF-0000-0000-0000-00000000000B',
            seqPageNo: 2,
            pageNo: 'an-older-revision.pdf',
            createDateTime: '2019-05-05 05:05:05',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));
        $this->archive->softDeleteSource('DEADBEEF-0000-0000-0000-00000000000B');
        $this->convertedContent(recordTheSource: false);

        $this->artisan('converters:purge-sources', ['--unrecorded' => true, '--confirm' => true])
            ->expectsOutputToContain('several hidden PDF rows and no record of which one was converted')
            ->assertSuccessful();

        $this->assertSame([], $this->archive->hardDeletedSources());
        $this->assertFileExists($this->sourcePath());
    }

    public function test_it_refuses_when_fewer_pages_are_on_show_than_the_conversion_recorded(): void
    {
        // The state an interrupted converters:undo leaves: a Done conversion whose pages are half
        // deleted. "At least one page row exists" would strip the source off a document mid-undo.
        $conversion = $this->convertedContent(pages: 3);
        $this->archive->softDeleteSource($conversion->pages()->orderBy('seq')->first()->mvd_id);

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('2 on show, 3 recorded')
            ->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
        $this->assertSame([], $this->archive->hardDeletedSources());
    }

    public function test_it_refuses_everything_when_the_archive_is_not_accepting_writes(): void
    {
        // Discovering this halfway through used to leave a record claiming a content was purged when
        // nothing had been - which then hid that content from every later run.
        config(['converter.archive.write_mode' => 'off']);
        $this->convertedContent();

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('not accepting writes')
            ->assertFailed();

        $this->assertSame(0, PurgedSource::query()->count());
        $this->assertFileExists($this->sourcePath());
    }

    public function test_a_record_left_by_a_failed_archive_delete_does_not_hide_the_content_for_ever(): void
    {
        $this->convertedContent();
        $archive = new class extends FakeArchive
        {
            public function hardDeleteSource(string $mvdId): bool
            {
                throw new RuntimeException('the archive is not answering');
            }
        };
        $archive->addContent(self::CONTENT, profileId: 12);
        $archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD, seqPageNo: 1, pageNo: 'the-original.pdf',
            createDateTime: '2023-02-01 07:43:38', format: 'Application/pdf', ftpSiteId: 1,
        ));
        $archive->softDeleteSource(self::SOURCE_MVD);
        $archive->insertPage(new PageInsert(
            contentId: self::CONTENT, seqPageNo: 1, createDateTime: '2026-09-14 10:00:00',
            format: 'Image/jpg', ftpSiteId: 1, thumbnail: 'x',
        ));
        $this->app->instance(ArchiveGateway::class, $archive);

        $this->artisan('converters:purge-sources', ['--confirm' => true])->assertSuccessful();

        // Nothing was destroyed, so nothing may claim it was: a record here would exclude the content
        // from eligible() for good.
        $this->assertSame(0, PurgedSource::query()->count());
        $this->assertFileExists($this->sourcePath());
    }

    public function test_a_file_is_never_deleted_while_its_archive_row_is_still_there(): void
    {
        // The recovery pass asks the row itself, ignoring Deleted, because converters:undo may have
        // put the source back on show since - and hiddenSourcesFor() would read that as "gone".
        $this->convertedContent();
        PurgedSource::query()->create([
            'content_id' => self::CONTENT,
            'mvd_id' => self::SOURCE_MVD,
            'remote_path' => self::FOLDER.'/'.self::SOURCE_MVD.'.pdf',
            'row_deleted' => false,
            'file_deleted' => false,
        ]);

        $this->artisan('converters:purge-sources', ['--confirm' => true])
            ->expectsOutputToContain('its archive row is still there')
            ->assertSuccessful();

        $this->assertFileExists($this->sourcePath());
    }

    private function putSourceFile(): void
    {
        $folder = $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, self::FOLDER);
        File::ensureDirectoryExists($folder);
        File::put($folder.DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf', 'a pdf');
    }

    private function sourcePath(): string
    {
        return $this->store.DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, self::FOLDER)
            .DIRECTORY_SEPARATOR.self::SOURCE_MVD.'.pdf';
    }
}
