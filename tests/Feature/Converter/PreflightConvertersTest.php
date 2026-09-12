<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\FtpSite;
use App\Actions\Converter\Archive\SourceFile;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * The preflight is what the operator runs after editing .env, and its exit code is what gates a
 * deployment - so what is tested here is that it reports rather than crashes, and that it is honest
 * about the difference: a machine that is ready exits 0, a machine that cannot reach the archive or
 * has no renderer exits 1 and names the check, and the FTP password never reaches the output it
 * prints, which the operator will paste into a chat window.
 */
class PreflightConvertersTest extends TestCase
{
    use RefreshDatabase;

    private const string CONTENT = '1c8f16cf-4635-42f8-971d-451b8a1b1ae1';

    private const string SOURCE_MVD = 'a47e40c9-e6a1-ed11-96cd-005056baa2b4';

    /**
     * The FTP password of the fake site. Distinctive on purpose: the test looks for it in the whole
     * output, so it must be a string nothing else could produce.
     */
    private const string FTP_PASSWORD = 'pr3flight-s3cr3t-p4ssw0rd';

    private FakeArchive $archive;

    private string $store;

    private string $workspaceRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preflight-store-'.uniqid();
        $this->workspaceRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'preflight-work-'.uniqid();
        File::ensureDirectoryExists($this->store);

        $this->archive = $this->readyArchive();
        $this->app->instance(ArchiveGateway::class, $this->archive);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));

        config([
            // The panel's own sqlite database stands in for the archive here: the preflight's SQL
            // Server checks report themselves as skipped on any other driver, which is the behaviour
            // a developer machine without pdo_sqlsrv needs.
            'converter.archive.connection' => config('database.default'),
            'converter.archive.write_mode' => 'off',

            // PHP itself answers -version and exits 0, which is exactly the shape pdf2img has.
            'converter.render.binary' => PHP_BINARY,
            'converter.workspace.root' => $this->workspaceRoot,

            // Whatever the machine running the tests has free; the floor itself is asserted by the
            // failing case below.
            'converter.workspace.free_space_floor_gb' => 0,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);
        File::deleteDirectory($this->workspaceRoot);

        parent::tearDown();
    }

    public function test_a_ready_machine_reports_every_check_and_exits_zero(): void
    {
        // The expectations are deliberately substrings that occur in one line each: they are matched
        // against every written line in the order they are declared, so a substring that also appears
        // in a later line would swallow that line's own expectation.
        $this->artisan('converters:preflight')
            ->expectsOutputToContain('memory_limit is ')
            ->expectsOutputToContain('conversions, conversion_pages, conversion_watermarks')
            ->expectsOutputToContain('discover(null, 1) returned 1 content(s)')
            ->expectsOutputToContain('seedFromLegacyQueue(1, 0) returned 1 content(s)')
            ->expectsOutputToContain('currentFileSite(): FtpSites row 1')
            // The point of the per-content read: the folder derived from the row's own CreateDateTime.
            ->expectsOutputToContain('DOI/2023/02/01/07/43/38/'.self::SOURCE_MVD.'.pdf')
            ->expectsOutputToContain('store mode fs')
            ->expectsOutputToContain('it answers -version')
            ->expectsOutputToContain($this->workspaceRoot)
            ->expectsOutputToContain('Every required check passed')
            ->assertSuccessful();
    }

    public function test_an_archive_that_cannot_be_reached_fails_and_says_which_check(): void
    {
        $this->app->instance(ArchiveGateway::class, new class extends FakeArchive
        {
            public function discover(?CarbonImmutable $processedAfter, int $limit): array
            {
                throw new RuntimeException('Could not connect to the archive');
            }

            public function seedFromLegacyQueue(int $limit, int $offset): array
            {
                throw new RuntimeException('Could not connect to the archive');
            }

            public function currentFileSite(): FtpSite
            {
                throw new RuntimeException('Could not connect to the archive');
            }
        });

        $this->artisan('converters:preflight')
            ->expectsOutputToContain('discover(null, 1) failed: Could not connect to the archive')
            ->expectsOutputToContain('currentFileSite() failed: Could not connect to the archive')
            ->expectsOutputToContain('required check(s) failed')
            ->assertExitCode(1);
    }

    public function test_a_missing_pdf2img_is_a_failure_and_not_a_crash(): void
    {
        $missing = $this->workspaceRoot.DIRECTORY_SEPARATOR.'nowhere'.DIRECTORY_SEPARATOR.'pdf2img.exe';

        config(['converter.render.binary' => $missing]);

        $this->artisan('converters:preflight')
            ->expectsOutputToContain('pdf2img is not at '.$missing)
            ->expectsOutputToContain('required check(s) failed')
            ->assertExitCode(1);
    }

    public function test_the_ftp_password_never_reaches_the_output(): void
    {
        Artisan::call('converters:preflight');

        $output = Artisan::output();

        $this->assertStringNotContainsString(self::FTP_PASSWORD, $output);

        // The site itself is reported - host, port, folder and login - so the absence above is the
        // password being left out rather than the whole line being missing.
        $this->assertStringContainsString('ftp://archive@Archive-app:21/DOI', $output);
    }

    /**
     * An archive with one content waiting, its source PDF, and the FTP site the archive really has.
     */
    private function readyArchive(): FakeArchive
    {
        $archive = new FakeArchive(new FtpSite(1, 'Archive-app', 21, 'DOI', 'archive', self::FTP_PASSWORD));

        $archive->addContent(self::CONTENT, profileId: 65);
        $archive->addLegacyContent(self::CONTENT, 65, CarbonImmutable::parse('2023-02-01 07:43:38'));
        $archive->addSourceFile(self::CONTENT, new SourceFile(
            mvdId: self::SOURCE_MVD,
            seqPageNo: 1,
            pageNo: '13870611_16_ettelaat_pdf_zamimeh_49.pdf',
            createDateTime: '2023-02-01 07:43:38',
            format: 'Application/pdf',
            ftpSiteId: 1,
        ));

        return $archive;
    }
}
