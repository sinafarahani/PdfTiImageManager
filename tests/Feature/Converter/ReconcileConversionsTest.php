<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\DiscoveredContent;
use App\Actions\Converter\Archive\FakeArchive;
use App\Actions\Converter\Archive\PageInsert;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Pipeline\ConversionQueue;
use App\Actions\Converter\Pipeline\ConversionStatus;
use App\Console\Commands\ReconcileConversions;
use App\Models\Conversion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * What used to be done by hand when a worker died mid-content: find the page rows that attempt had
 * written, delete them, free the content in the archive and start it again. The ledger makes it exact,
 * and these tests hold it to that - a half-converted content must be left as if it had never started.
 */
class ReconcileConversionsTest extends TestCase
{
    use RefreshDatabase;

    private FakeArchive $archive;

    private string $store;

    private ConversionQueue $queue;

    protected function setUp(): void
    {
        parent::setUp();

        $this->archive = new FakeArchive;
        $this->app->instance(ArchiveGateway::class, $this->archive);
        $this->queue = new ConversionQueue;

        // A store of its own, because reclaiming deletes the images the interrupted attempt uploaded.
        // Without this the command resolves the real FTP client and spends the test's time failing to
        // reach a server that is not there.
        $this->store = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-reconcile-'.uniqid();
        File::ensureDirectoryExists($this->store);
        $this->app->instance(FileStore::class, new LocalFileStore($this->store));

        config([
            'converter.failure.stale_after_minutes' => 60,
            'converter.failure.max_attempts' => 3,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->store);

        parent::tearDown();
    }

    public function test_a_machine_that_has_just_started_takes_its_own_work_back_at_once(): void
    {
        // After a reboot nothing of ours is running, so waiting out the full staleness window would
        // leave the machine idle for an hour and a half over work it could resume immediately. The
        // supervisor asks for a short window at startup; a worker orphaned by a crash keeps reporting
        // and is still left alone.
        [$conversion] = $this->interruptedConversion();
        $conversion->forceFill(['heartbeat_at' => now()->subMinutes(5)])->save();

        $this->artisan('converters:reconcile', ['--minutes' => 2])
            ->expectsOutputToContain('Reclaimed 1 conversion(s)')
            ->assertSuccessful();

        $this->assertSame(ConversionStatus::Pending, $conversion->refresh()->status);
    }

    public function test_a_short_window_still_leaves_a_worker_that_is_reporting_alone(): void
    {
        [$conversion] = $this->interruptedConversion();
        $conversion->forceFill(['heartbeat_at' => now()->subSeconds(30)])->save();

        $this->artisan('converters:reconcile', ['--minutes' => 2])
            ->expectsOutputToContain('Reclaimed 0 conversion(s)')
            ->assertSuccessful();

        $this->assertSame(ConversionStatus::Claimed, $conversion->refresh()->status);
    }

    public function test_a_stale_conversion_is_cleaned_up_released_and_queued_again(): void
    {
        [$conversion, $uploadedPath] = $this->interruptedConversion();

        $this->artisan('converters:reconcile')
            ->expectsOutputToContain('Reclaimed 1 conversion(s); deleted 1 of 1 image(s)')
            ->assertSuccessful();

        // The image the interrupted attempt had uploaded goes with its row: nothing in the archive
        // points at it any more, and the next attempt uploads its own under a new id.
        $this->assertFileDoesNotExist($this->localPathOf($uploadedPath));

        $conversion->refresh();

        $this->assertSame(ConversionStatus::Pending, $conversion->status);
        $this->assertSame(1, $conversion->attempts);
        $this->assertNull($conversion->worker);
        $this->assertNull($conversion->heartbeat_at);

        // Nothing of the interrupted attempt is left: no page rows in the archive, no ledger rows
        // here, and the content belongs to nobody again.
        $this->assertSame([], $this->archive->pages());
        $this->assertSame(0, $conversion->pages()->count());
        $this->assertNull($this->archive->ownerOf('C1'));
        $this->assertCount(0, $this->queue->stale());
    }

    public function test_reclaiming_hands_the_uploaded_images_to_the_caller(): void
    {
        [$conversion, $uploadedPath] = $this->interruptedConversion();

        $orphans = $this->app->make(ReconcileConversions::class)
            ->reclaim($conversion, $this->archive, $this->queue);

        // Only the page that really reached the FTP site: deleting a file the pipeline never wrote
        // would be a guess, which is the thing this ledger exists to avoid.
        $this->assertSame([$uploadedPath], $orphans);
    }

    public function test_a_conversion_it_does_not_hold_is_left_completely_alone(): void
    {
        [$conversion] = $this->interruptedConversion();

        $command = $this->app->make(ReconcileConversions::class);

        $this->assertNotNull($command->reclaim($conversion, $this->archive, $this->queue));

        // A second reconciler - or the same one running twice because nothing stopped it - arrives
        // with a model that still looks claimed. By now a new attempt may already be writing pages for
        // this content, and deleting those is the one mistake the ledger exists to prevent.
        $this->queue->recordPage($conversion, 1, 'NEW-ATTEMPT-PAGE');

        $this->assertNull($command->reclaim($conversion, $this->archive, $this->queue));
        $this->assertSame(1, $conversion->pages()->count());
        $this->assertSame(1, Conversion::query()->sole()->attempts);
    }

    public function test_a_content_that_has_used_up_its_attempts_stays_failed(): void
    {
        [$conversion] = $this->interruptedConversion();

        $conversion->forceFill(['attempts' => 2])->save();

        $this->artisan('converters:reconcile')->assertSuccessful();

        $conversion->refresh();

        $this->assertSame(ConversionStatus::Failed, $conversion->status);
        $this->assertSame(3, $conversion->attempts);
        $this->assertSame([], $this->archive->pages());
        $this->assertNull($this->archive->ownerOf('C1'));
    }

    public function test_conversions_whose_worker_is_still_reporting_are_left_alone(): void
    {
        $this->interruptedConversion();

        Conversion::query()->update(['heartbeat_at' => now()]);

        $this->artisan('converters:reconcile')
            ->expectsOutputToContain('Reclaimed 0 conversion(s)')
            ->assertSuccessful();

        $this->assertCount(2, $this->archive->pages());
        $this->assertSame('worker-1', $this->archive->ownerOf('C1'));
        $this->assertSame(ConversionStatus::Claimed, Conversion::query()->sole()->status);
    }

    /**
     * A content a worker took, wrote two page rows for and uploaded the first image of, before it
     * stopped reporting.
     *
     * @return array{Conversion, string}
     */
    private function interruptedConversion(): array
    {
        $this->archive->addContent('C1');
        $this->archive->reserve('C1', 'worker-1');

        $this->queue->add([new DiscoveredContent('C1', 1, null)]);

        $conversion = $this->queue->claim('worker-1', 1)->sole();

        $first = $this->archive->insertPage(new PageInsert('C1', 1, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-1'));
        $second = $this->archive->insertPage(new PageInsert('C1', 2, '2025-01-07 16:39:53', 'Image/jpg', 1, 'thumb-2'));

        $uploadedPath = '/DOI/2025/01/07/16/39/53/'.$first.'.jpg';

        $this->queue->markPageUploaded($this->queue->recordPage($conversion, 1, $first), $uploadedPath, 91_204);
        $this->queue->recordPage($conversion, 2, $second);

        // The image really is on the store, so reclaiming has something to take back off it.
        File::ensureDirectoryExists(dirname($this->localPathOf($uploadedPath)));
        File::put($this->localPathOf($uploadedPath), 'a page image');

        $conversion->forceFill(['heartbeat_at' => now()->subMinutes(90)])->save();

        return [$conversion, $uploadedPath];
    }

    private function localPathOf(string $remotePath): string
    {
        return $this->store.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, ltrim($remotePath, '/'));
    }
}
