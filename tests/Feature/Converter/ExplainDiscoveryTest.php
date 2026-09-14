<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\SourceFile;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExplainDiscoveryTest extends TestCase
{
    use RefreshDatabase;

    private FakeArchive $archive;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = new FakeArchive;
        $this->app->instance(ArchiveGateway::class, $this->archive);
    }

    public function test_it_says_nothing_is_missed_when_the_older_contents_are_all_converted(): void
    {
        // The ordinary answer, and the one that is indistinguishable from a blind spot without asking:
        // discovery starts in July because everything older was converted years ago.
        $this->content('C1', '2022-01-01 10:00:00');
        $this->content('C2', '2023-05-05 10:00:00');
        $this->archive->markConverted('C1');
        $this->archive->markConverted('C2');

        $this->artisan('converters:explain-discovery', ['--before' => '2026-07-29 21:40:14'])
            ->expectsOutputToContain('Nothing older is being missed.')
            ->expectsOutputToContain('ProcessDate is when the archive processed the content')
            ->assertSuccessful();
    }

    public function test_it_warns_when_older_contents_would_be_offered(): void
    {
        $this->content('C1', '2022-01-01 10:00:00');
        $this->archive->markConverted('C1');
        $this->content('C2', '2023-05-05 10:00:00');

        $this->artisan('converters:explain-discovery', ['--before' => '2026-07-29 21:40:14'])
            ->expectsOutputToContain('1 content(s) older than that date would be offered')
            ->expectsOutputToContain('--all --restart')
            ->assertSuccessful();
    }

    public function test_it_names_reserved_contents_as_their_own_problem(): void
    {
        // The retired pipeline left dead workers' GUIDs in Reserved, and a content held that way can
        // never be offered again by anything - which no amount of rescanning fixes.
        $this->content('C1', '2022-01-01 10:00:00');
        $this->archive->reserve('C1', 'a-worker-that-died');

        $this->artisan('converters:explain-discovery', ['--before' => '2026-07-29 21:40:14'])
            ->expectsOutputToContain('reserved by somebody')
            ->expectsOutputToContain('converters:retry')
            ->assertSuccessful();
    }

    public function test_contents_newer_than_the_date_are_not_counted(): void
    {
        $this->content('C1', '2026-08-01 10:00:00');

        $this->artisan('converters:explain-discovery', ['--before' => '2026-07-29 21:40:14'])
            ->expectsOutputToContain('no content with a PDF processed before that date at all')
            ->assertSuccessful();
    }

    public function test_the_date_has_to_be_given_and_has_to_be_a_date(): void
    {
        $this->artisan('converters:explain-discovery')
            ->expectsOutputToContain('Name the date to look before')
            ->assertFailed();

        $this->artisan('converters:explain-discovery', ['--before' => 'whenever'])
            ->expectsOutputToContain('is not a date this understands')
            ->assertFailed();
    }

    private function content(string $contentId, string $processedAt): void
    {
        $this->archive->addContent($contentId, 12, CarbonImmutable::parse($processedAt));
        $this->archive->addSourceFile($contentId, new SourceFile(
            mvdId: 'MVD-'.$contentId,
            seqPageNo: 1,
            pageNo: 'a.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));
    }
}
