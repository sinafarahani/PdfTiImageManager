<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Archive\FtpSite;
use App\Actions\Converter\Ftp\ArchiveFtpClient;
use App\Actions\Converter\Ftp\FileStoreException;
use DateInterval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FtpStubServer;
use Tests\TestCase;

class ArchiveFtpClientTest extends TestCase
{
    private FtpStubServer $server;

    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->server = new FtpStubServer;
        $this->workspace = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ftp-client-'.uniqid();
        File::makeDirectory($this->workspace, recursive: true);
        Sleep::fake();
    }

    protected function tearDown(): void
    {
        $this->server->stop();
        File::deleteDirectory($this->workspace);
        Sleep::fake(false);

        parent::tearDown();
    }

    /**
     * @return list<array{string}>
     */
    public static function listingStyles(): array
    {
        return [['bare'], ['full'], ['windows']];
    }

    #[DataProvider('listingStyles')]
    public function test_lists_the_names_of_a_folder_however_the_server_spells_them(string $style): void
    {
        $this->server->nlistStyle($style);
        $this->server->write('DOI/2025/01/07/first.jpg', 'a');
        $this->server->write('DOI/2025/01/07/second.jpg', 'b');
        $client = $this->client();

        $names = $client->list('/2025/01//07/');
        sort($names);

        $this->assertSame(['first.jpg', 'second.jpg'], $names);
        $this->assertContains('NLST /DOI/2025/01/07', $this->server->commands());
    }

    public function test_a_folder_that_is_not_there_is_a_failure(): void
    {
        $client = $this->client(['attempts' => 1]);

        $failure = $this->failureOf(fn () => $client->list('2025/01/07'));

        $this->assertFalse($failure->transient);
        $this->assertStringContainsString('/DOI/2025/01/07', $failure->getMessage());
    }

    public function test_a_listing_the_server_never_answers_gives_up_at_the_timeout(): void
    {
        $this->server->stall('NLST');
        $this->server->write('DOI/2025/keep.jpg', 'a');
        $client = $this->client(['timeout' => 1, 'attempts' => 1]);

        $started = microtime(true);
        $failure = $this->failureOf(fn () => $client->list('2025'));
        $elapsed = microtime(true) - $started;

        $this->assertTrue($failure->transient);
        $this->assertGreaterThanOrEqual(1.0, $elapsed);
        $this->assertLessThan(15.0, $elapsed, 'the listing waited far past its timeout');
    }

    public function test_downloads_a_file_and_reports_the_bytes_written(): void
    {
        $this->server->write('DOI/2025/01/07/16/39/53/source.pdf', $contents = random_bytes(4096));
        $client = $this->client();
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $bytes = $client->download('2025/01/07/16/39/53/source.pdf', $local);

        $this->assertSame(4096, $bytes);
        $this->assertSame($contents, file_get_contents($local));
    }

    public function test_a_download_that_stops_half_way_leaves_no_file_behind(): void
    {
        $this->server->truncateDownloads(512);
        $this->server->write('DOI/2025/source.pdf', random_bytes(8192));
        $client = $this->client(['timeout' => 1, 'attempts' => 1]);
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $failure = $this->failureOf(fn () => $client->download('2025/source.pdf', $local));

        $this->assertTrue($failure->transient);
        $this->assertFileDoesNotExist($local);
        $this->assertFileDoesNotExist($local.'.part');
    }

    public function test_a_download_the_server_cut_short_and_called_complete_is_refused(): void
    {
        $this->server->truncateDownloads(512, announceComplete: true);
        $this->server->write('DOI/2025/source.pdf', random_bytes(8192));
        $client = $this->client(['attempts' => 1]);
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $failure = $this->failureOf(fn () => $client->download('2025/source.pdf', $local));

        // ext-ftp reports this transfer as a success, so only the size the server itself keeps can
        // tell it from a whole PDF.
        $this->assertTrue($failure->transient);
        $this->assertStringContainsString('brought 512 of 8192 bytes', $failure->getMessage());
        $this->assertFileDoesNotExist($local);
        $this->assertFileDoesNotExist($local.'.part');
    }

    public function test_a_server_that_answers_for_ever_without_finishing_is_cut_off(): void
    {
        // Every chunk arrives inside the read timeout, so the session's own timeout is never going
        // to fire; what has to stop this is the clock on the transfer as a whole.
        $this->server->trickleDownloads(bytes: 256, pause: 1);
        $this->server->write('DOI/2025/source.pdf', random_bytes(32768));
        $client = $this->client(['timeout' => 5, 'transfer_timeout' => 5, 'attempts' => 1]);
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $started = microtime(true);
        $failure = $this->failureOf(fn () => $client->download('2025/source.pdf', $local));
        $elapsed = microtime(true) - $started;

        $this->assertTrue($failure->transient);
        $this->assertGreaterThanOrEqual(5.0, $elapsed);
        $this->assertLessThan(20.0, $elapsed, 'the transfer was left to trickle');
        $this->assertFileDoesNotExist($local);
        $this->assertFileDoesNotExist($local.'.part');
    }

    public function test_uploads_a_file_creating_the_folders_it_needs_and_checking_the_size(): void
    {
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, $contents = random_bytes(1200));
        $client = $this->client();

        $stored = $client->upload($local, '2025/01/07/16/39/53/8DB1E6AE.jpg');

        $this->assertSame(1200, $stored);
        $this->assertSame($contents, $this->server->contents('DOI/2025/01/07/16/39/53/8DB1E6AE.jpg'));
        $this->assertContains('MKD /DOI/2025/01/07/16/39/53', $this->server->commands());
        $this->assertContains('STOR /DOI/2025/01/07/16/39/53/8DB1E6AE.jpg', $this->server->commands());
    }

    public function test_an_upload_the_server_stored_short_is_not_accepted(): void
    {
        $this->server->truncateUploads(300);
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, random_bytes(1200));
        $client = $this->client(['attempts' => 1]);

        $failure = $this->failureOf(fn () => $client->upload($local, '2025/01/07/page.jpg'));

        $this->assertStringContainsString('stored 300 of 1200 bytes', $failure->getMessage());
    }

    public function test_the_pages_of_one_content_share_a_session_and_its_folders(): void
    {
        $client = $this->client();
        $folder = '2025/01/07/16/39/53';

        foreach (['first', 'second', 'third'] as $page) {
            $local = $this->workspace.DIRECTORY_SEPARATOR.$page.'.jpg';
            file_put_contents($local, random_bytes(128));
            $this->assertSame(128, $client->upload($local, "{$folder}/{$page}.jpg"));
        }

        $commands = $this->server->commands();

        // Seven segments of folder, created once for the whole content and not once per page: the
        // archive's folders are six levels deep and a content can run to hundreds of pages.
        $this->assertSame(7, count(array_filter($commands, fn (string $c): bool => str_starts_with($c, 'MKD'))));
        $this->assertSame(3, count(array_filter($commands, fn (string $c): bool => str_starts_with($c, 'STOR'))));
        $this->assertSame(1, count(array_filter($commands, fn (string $c): bool => str_starts_with($c, 'USER'))));
    }

    public function test_a_session_dropped_between_two_pages_creates_its_folders_again(): void
    {
        $this->server->dropTimes('STOR', 1);
        $client = $this->client(['attempts' => 1]);
        $folder = '2025/01/07/16/39/53';

        // The session the worker opened before the long rendering step. The server closes it while
        // the worker is busy, and the first upload afterwards is the one that finds out.
        $this->assertNull($client->size("{$folder}/first.jpg"));

        foreach (['first', 'second'] as $page) {
            $local = $this->workspace.DIRECTORY_SEPARATOR.$page.'.jpg';
            file_put_contents($local, random_bytes(64));
            $client->upload($local, "{$folder}/{$page}.jpg");
        }

        $this->assertNotFalse($this->server->contents("DOI/{$folder}/first.jpg"));
        $this->assertNotFalse($this->server->contents("DOI/{$folder}/second.jpg"));

        // What the client believes about the server's folders belongs to the session it learnt it
        // in; the new session asks again rather than storing into a folder it never created.
        $this->assertSame(14, count(array_filter($this->server->commands(), fn (string $c): bool => str_starts_with($c, 'MKD'))));
        Sleep::assertNeverSlept();
    }

    public function test_a_server_that_has_run_out_of_space_is_tried_again(): void
    {
        // The reply code, which is the only thing that says "temporary" here, is thrown away by
        // ext-ftp before the client can see it, and the text says nothing either way.
        $this->server->failTimes('STOR', 1, '452 Insufficient storage space');
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, $contents = random_bytes(96));
        $client = $this->client(['attempts' => 2]);

        $this->assertSame(96, $client->upload($local, '2025/01/07/page.jpg'));
        $this->assertSame($contents, $this->server->contents('DOI/2025/01/07/page.jpg'));
    }

    public function test_passive_mode_is_asked_for_and_never_traded_for_active_mode(): void
    {
        $this->server->failTimes('PASV', 1, '500 PASV not supported right now');
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, random_bytes(32));
        $client = $this->client(['attempts' => 2]);

        $this->assertSame(32, $client->upload($local, '2025/page.jpg'));

        $commands = $this->server->commands();
        $this->assertContains('PASV', $commands);
        $this->assertSame([], array_values(array_filter(
            $commands,
            fn (string $command): bool => str_starts_with($command, 'PORT') || str_starts_with($command, 'EPRT'),
        )), 'the client fell back to active mode, which the archive\'s firewall drops');
    }

    public function test_a_path_that_leaves_the_site_folder_is_refused(): void
    {
        $client = $this->client(['attempts' => 1]);

        $failure = $this->failureOf(fn () => $client->size('2025/../../etc/passwd'));

        $this->assertFalse($failure->transient);
        $this->assertSame([], $this->server->commands());
    }

    public function test_a_transient_failure_is_tried_again(): void
    {
        $this->server->failTimes('STOR', 1, '450 Busy, try again later');
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, $contents = random_bytes(64));
        $client = $this->client(['attempts' => 3, 'retry_seconds' => 5]);

        $stored = $client->upload($local, '2025/01/07/page.jpg');

        $this->assertSame(64, $stored);
        $this->assertSame($contents, $this->server->contents('DOI/2025/01/07/page.jpg'));
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(fn (DateInterval $waited): bool => $waited->s === 5);
    }

    public function test_a_file_that_is_not_there_is_not_tried_again(): void
    {
        $client = $this->client(['attempts' => 3]);
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $failure = $this->failureOf(fn () => $client->download('2025/missing.pdf', $local));

        $this->assertFalse($failure->transient);
        $this->assertSame(1, count(array_filter($this->server->commands(), fn (string $command): bool => str_starts_with($command, 'RETR'))));
        Sleep::assertNeverSlept();
    }

    public function test_a_file_that_is_gone_is_recognised_even_when_the_refusal_says_nothing(): void
    {
        // What the archive's own site answers for its thousands of stale rows: no reply code and no
        // wording to match on - the whole message is "End". Read for meaning, that looked like a
        // transient failure, so every dead row cost three FTP attempts and then three conversion
        // attempts. The folder is asked instead, and it answers plainly.
        $this->server->write('DOI/2025/still-here.pdf', 'x');
        $this->server->failTimes('RETR', 3, 'End');
        $client = $this->client(['attempts' => 3, 'retry_seconds' => 5]);

        $failure = $this->failureOf(fn () => $client->download('2025/gone.pdf', $this->workspace.DIRECTORY_SEPARATOR.'gone.pdf'));

        $this->assertTrue($failure->absent);
        $this->assertFalse($failure->transient);
        $this->assertStringContainsString('not on the server', $failure->getMessage());
        $this->assertSame(1, count(array_filter($this->server->commands(), fn (string $command): bool => str_starts_with($command, 'RETR'))));
        Sleep::assertNeverSlept();
    }

    public function test_a_file_whose_whole_folder_is_gone_is_recognised_too(): void
    {
        // The archive's stale rows are not all one missing file: whole day folders are gone, so the
        // listing that would answer "the file is not there" cannot be had either. The folder is asked
        // directly then, because nothing can be inside a folder that does not exist.
        $this->server->failTimes('RETR', 3, 'End');
        $client = $this->client(['attempts' => 3, 'retry_seconds' => 5]);

        $failure = $this->failureOf(fn () => $client->download('2023/01/17/10/57/21/gone.pdf', $this->workspace.DIRECTORY_SEPARATOR.'gone.pdf'));

        $this->assertTrue($failure->absent);
        $this->assertSame(1, count(array_filter($this->server->commands(), fn (string $command): bool => str_starts_with($command, 'RETR'))));
        Sleep::assertNeverSlept();
    }

    public function test_the_same_silent_refusal_is_still_retried_when_the_file_is_there(): void
    {
        // The other half of it: an unreadable refusal for a file the folder does list is the transfer
        // having a bad moment, and giving up on that would throw away a content that is perfectly fine.
        $this->server->write('DOI/2025/page.pdf', $contents = random_bytes(32));
        $this->server->failTimes('RETR', 1, 'End');
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.pdf';
        $client = $this->client(['attempts' => 3, 'retry_seconds' => 5]);

        $this->assertSame(32, $client->download('2025/page.pdf', $local));
        $this->assertSame($contents, file_get_contents($local));
        Sleep::assertSleptTimes(1);
    }

    public function test_a_session_the_server_dropped_is_opened_again_without_waiting(): void
    {
        $this->server->dropTimes('SIZE', 1);
        $this->server->write('DOI/2025/page.jpg', str_repeat('x', 12));
        $client = $this->client(['attempts' => 1]);

        $this->assertSame(['page.jpg'], $client->list('2025'));
        $this->assertSame(12, $client->size('2025/page.jpg'));

        // The second session is not a retry: it costs no attempt and no wait.
        Sleep::assertNeverSlept();
    }

    public function test_a_rejected_login_is_not_tried_again_and_never_names_the_password(): void
    {
        $this->server->rejectLogins();
        $client = $this->client(['attempts' => 3]);

        $failure = $this->failureOf(fn () => $client->list('2025'));
        $this->failureOf(fn () => $client->upload(__FILE__, '2025/page.jpg'));

        $this->assertFalse($failure->transient);
        $this->assertStringContainsString('FTP login as '.FtpStubServer::USERNAME, $failure->getMessage());
        $this->assertStringNotContainsString(FtpStubServer::PASSWORD, $failure->getMessage());
        $this->assertStringContainsString('***', $failure->getMessage());

        // One refused login for the whole client: the archive's site locks an account out after a
        // handful of them, and a content has hundreds of pages to upload.
        $this->assertSame(1, count(array_filter($this->server->commands(), fn (string $c): bool => str_starts_with($c, 'PASS'))));
        Sleep::assertNeverSlept();
    }

    public function test_reports_the_size_of_a_file_and_nothing_for_one_that_is_gone(): void
    {
        $this->server->write('DOI/2025/page.jpg', str_repeat('x', 77));
        $client = $this->client();

        $this->assertSame(77, $client->size('2025/page.jpg'));
        $this->assertNull($client->size('2025/other.jpg'));
    }

    public function test_deletes_a_file_and_accepts_one_that_is_already_gone(): void
    {
        $this->server->write('DOI/2025/page.jpg', 'a');
        $client = $this->client();

        $client->delete('2025/page.jpg');
        $client->delete('2025/page.jpg');

        $this->assertFalse($this->server->contents('DOI/2025/page.jpg'));
        Sleep::assertNeverSlept();
    }

    /**
     * @param  array<string, int>  $settings
     */
    private function client(array $settings = []): ArchiveFtpClient
    {
        $this->server->start();

        return new ArchiveFtpClient(
            new FtpSite(1, '127.0.0.1', $this->server->port(), 'DOI', FtpStubServer::USERNAME, FtpStubServer::PASSWORD),
            array_merge(['connect_timeout' => 5, 'timeout' => 3, 'attempts' => 2, 'retry_seconds' => 5], $settings),
        );
    }

    private function failureOf(callable $work): FileStoreException
    {
        try {
            $work();
        } catch (FileStoreException $exception) {
            return $exception;
        }

        $this->fail('The operation was expected to fail.');
    }
}
