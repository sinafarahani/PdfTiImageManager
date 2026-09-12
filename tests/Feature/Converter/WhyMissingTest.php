<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Actions\Converter\Pipeline\Stage;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhyMissingTest extends TestCase
{
    use RefreshDatabase;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = new FakeArchive;
        $this->app->instance(ArchiveGateway::class, $this->archive);
    }

    public function test_it_says_so_when_nothing_is_missing(): void
    {
        $this->missing('2023/01/05/16/39/53');
        Conversion::query()->update(['failure_stage' => Stage::Render]);

        $this->artisan('converters:why-missing')
            ->expectsOutputToContain('No content has been recorded as having no source file.')
            ->assertSuccessful();
    }

    public function test_it_groups_the_missing_files_by_the_day_folder_they_were_looked_for_in(): void
    {
        // The point of the count: a day folder holding many of them can be asked about once instead of
        // once per file, and that is only worth building if the files really do share their folders.
        $this->missing('2023/01/05/16/39/53');
        $this->missing('2023/01/05/17/02/11');
        $this->missing('2023/01/05/18/44/09');
        $this->missing('2024/06/30/08/15/00');

        $this->artisan('converters:why-missing')
            ->expectsOutputToContain('3 content(s) in 2023/01/05')
            ->expectsOutputToContain('1 content(s) in 2024/06/30')
            ->expectsOutputToContain('2 instead of 4')
            ->assertSuccessful();
    }

    public function test_it_reports_what_the_archive_says_about_a_sample(): void
    {
        $content = $this->missing('2023/01/05/16/39/53')->content_id;
        $this->archive->addContent($content);
        $this->archive->addSourceFile($content, new SourceFile(
            mvdId: '8E3C2A40-0000-0000-0000-000000000001',
            seqPageNo: 1,
            pageNo: 'report.pdf',
            createDateTime: '2023-01-05 16:39:53',
            format: 'application/pdf',
            ftpSiteId: $this->archive->currentFileSite()->id,
        ));

        $this->artisan('converters:why-missing')
            ->expectsOutputToContain('a live PDF row on site')
            ->expectsOutputToContain('the files really are gone')
            ->assertSuccessful();
    }

    public function test_it_warns_when_the_archive_could_have_answered_without_the_file_store(): void
    {
        // A content with no live PDF row is refused before the file store is opened, so one recorded as
        // having no file is a contradiction worth showing rather than a number to average away.
        $this->missing('2023/01/05/16/39/53');

        $this->artisan('converters:why-missing')
            ->expectsOutputToContain('no PDF row at all')
            ->expectsOutputToContain('That should not happen')
            ->assertSuccessful();
    }

    public function test_it_warns_when_the_file_is_being_looked_for_on_the_wrong_site(): void
    {
        $content = $this->missing('2023/01/05/16/39/53')->content_id;
        $this->archive->addContent($content);
        $this->archive->addSourceFile($content, new SourceFile(
            mvdId: '8E3C2A40-0000-0000-0000-000000000002',
            seqPageNo: 1,
            pageNo: 'report.pdf',
            createDateTime: '2023-01-05 16:39:53',
            format: 'application/pdf',
            ftpSiteId: $this->archive->currentFileSite()->id + 7,
        ));

        $this->artisan('converters:why-missing')
            ->expectsOutputToContain('being looked for on the wrong site')
            ->assertSuccessful();
    }

    private function missing(string $folder): Conversion
    {
        return Conversion::query()->create([
            'content_id' => fake()->uuid(),
            'profile_id' => 65,
            'status' => ConversionStatus::Failed,
            'failure_stage' => Stage::Missing,
            'failure_reason' => "the source PDF is not on the file store: {$folder}/".fake()->uuid().'.pdf',
            'finished_at' => now(),
        ]);
    }
}
