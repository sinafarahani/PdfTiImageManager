<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The sweep exists because a killed worker leaves a folder with a PDF and a document's worth of 300
 * dpi images in it, and a full staging drive is what cost the old pipeline 206 contents. It is also
 * the one command in the pipeline that deletes something nobody asked it to delete, so what is tested
 * here is mostly what it must leave alone: a folder whose content is being converted right now, and a
 * folder that is too young to be an orphan.
 */
class SweepWorkspacesTest extends TestCase
{
    use RefreshDatabase;

    private const string ABANDONED = 'c0ffee00-0000-4000-8000-000000000001';

    private const string BUSY = 'c0ffee00-0000-4000-8000-000000000002';

    private const string FRESH = 'c0ffee00-0000-4000-8000-000000000003';

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sweep-work-'.uniqid();
        File::ensureDirectoryExists($this->root);

        config([
            'converter.workspace.root' => $this->root,
            'converter.workspace.sweep_after_minutes' => 60,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);

        parent::tearDown();
    }

    public function test_it_deletes_an_old_folder_no_conversion_is_using(): void
    {
        // Exactly what a worker killed mid-conversion leaves behind: the downloaded PDF and the pages
        // it had rendered, in a folder whose conversion has since been reclaimed and converted.
        $folder = $this->workspace(self::ABANDONED, ageMinutes: 180, files: ['source.pdf', 'page0001.jpg']);

        $this->conversion(self::ABANDONED, ConversionStatus::Done);

        $this->artisan('converters:sweep')
            ->expectsOutputToContain('deleted '.strtolower(self::ABANDONED))
            ->expectsOutputToContain('Deleted 1 workspace folder(s)')
            ->assertSuccessful();

        $this->assertFalse(File::isDirectory($folder));
    }

    public function test_it_keeps_the_folder_of_a_conversion_that_is_being_converted(): void
    {
        // Old enough to look abandoned - a 500 page content takes a while - but a worker is holding it,
        // and deleting the folder underneath it would fail that content.
        $folder = $this->workspace(self::BUSY, ageMinutes: 180);

        $this->conversion(self::BUSY, ConversionStatus::Claimed);

        $this->artisan('converters:sweep')
            ->expectsOutputToContain('kept '.strtolower(self::BUSY).': a worker is converting it')
            ->expectsOutputToContain('Deleted 0 workspace folder(s)')
            ->assertSuccessful();

        $this->assertTrue(File::isDirectory($folder));
    }

    public function test_it_keeps_a_folder_that_was_touched_recently(): void
    {
        // No conversion row at all, which is what a folder opened seconds ago looks like from here:
        // the row exists on another machine, or the claim has not been committed yet.
        $folder = $this->workspace(self::FRESH, ageMinutes: 5);

        $this->artisan('converters:sweep')
            ->expectsOutputToContain('kept '.strtolower(self::FRESH).': touched less than 60 minute(s) ago')
            ->expectsOutputToContain('Deleted 0 workspace folder(s)')
            ->assertSuccessful();

        $this->assertTrue(File::isDirectory($folder));
    }

    public function test_a_dry_run_reports_what_it_would_delete_and_deletes_nothing(): void
    {
        $folder = $this->workspace(self::ABANDONED, ageMinutes: 180);

        $this->artisan('converters:sweep', ['--dry-run' => true])
            ->expectsOutputToContain('would delete '.strtolower(self::ABANDONED))
            ->expectsOutputToContain('Would delete 1 workspace folder(s)')
            ->assertSuccessful();

        $this->assertTrue(File::isDirectory($folder));
    }

    /**
     * A workspace folder, named the way ContentWorkspace names it, last touched $ageMinutes ago.
     *
     * @param  list<string>  $files
     */
    private function workspace(string $contentId, int $ageMinutes, array $files = []): string
    {
        $folder = $this->root.DIRECTORY_SEPARATOR.strtolower($contentId);
        File::ensureDirectoryExists($folder);

        foreach ($files as $file) {
            File::put($folder.DIRECTORY_SEPARATOR.$file, 'left behind');
        }

        // The age is set last, because writing a file into a folder moves the folder's own timestamp -
        // which is exactly why the sweep reads it: it is when work last happened there.
        touch($folder, now()->subMinutes($ageMinutes)->getTimestamp());
        clearstatcache(true, $folder);

        return $folder;
    }

    private function conversion(string $contentId, ConversionStatus $status): Conversion
    {
        return Conversion::query()->create([
            'content_id' => $contentId,
            'profile_id' => 65,
            'status' => $status,
            'worker' => $status === ConversionStatus::Claimed ? 'worker-1' : null,
            'claimed_at' => $status === ConversionStatus::Claimed ? now()->subMinutes(180) : null,
        ]);
    }
}
