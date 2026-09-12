<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Ftp\FileStoreException;
use App\Actions\Converter\Ftp\LocalFileStore;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LocalFileStoreTest extends TestCase
{
    private string $root;

    private string $workspace;

    private LocalFileStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'local-store-'.uniqid();
        $this->root = $base.DIRECTORY_SEPARATOR.'site';
        $this->workspace = $base.DIRECTORY_SEPARATOR.'work';
        File::makeDirectory($this->root, recursive: true);
        File::makeDirectory($this->workspace, recursive: true);
        $this->store = new LocalFileStore($this->root);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(dirname($this->root));

        parent::tearDown();
    }

    public function test_lists_the_names_of_a_folder(): void
    {
        $this->write('2025/01/07/second.jpg', 'b');
        $this->write('2025/01/07/first.jpg', 'a');

        $this->assertSame(['first.jpg', 'second.jpg'], $this->store->list('/2025/01//07/'));
    }

    public function test_a_folder_that_is_not_there_is_a_failure(): void
    {
        $failure = $this->failureOf(fn () => $this->store->list('2025/01/07'));

        $this->assertFalse($failure->transient);
        $this->assertStringContainsString('2025', $failure->getMessage());
    }

    public function test_downloads_a_file_and_reports_the_bytes_written(): void
    {
        $this->write('2025/01/07/16/39/53/source.pdf', $contents = random_bytes(4096));
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $bytes = $this->store->download('2025/01/07/16/39/53/source.pdf', $local);

        $this->assertSame(4096, $bytes);
        $this->assertSame($contents, file_get_contents($local));
        $this->assertFileDoesNotExist($local.'.part');
    }

    public function test_a_download_of_a_file_that_is_not_there_leaves_nothing_behind(): void
    {
        $local = $this->workspace.DIRECTORY_SEPARATOR.'source.pdf';

        $failure = $this->failureOf(fn () => $this->store->download('2025/missing.pdf', $local));

        $this->assertFalse($failure->transient);
        $this->assertFileDoesNotExist($local);
        $this->assertFileDoesNotExist($local.'.part');
    }

    public function test_a_download_into_a_folder_that_is_not_there_is_not_tried_again(): void
    {
        $this->write('2025/source.pdf', 'a');

        $failure = $this->failureOf(fn () => $this->store->download(
            '2025/source.pdf',
            $this->workspace.DIRECTORY_SEPARATOR.'gone'.DIRECTORY_SEPARATOR.'source.pdf',
        ));

        // A workspace folder that is not there is not something a second attempt creates, and the
        // FTP client says the same, so the pipeline behaves the same way on either store.
        $this->assertFalse($failure->transient);
    }

    public function test_a_path_that_leaves_the_store_is_refused(): void
    {
        $failure = $this->failureOf(fn () => $this->store->size('2025/../../../windows/win.ini'));

        $this->assertFalse($failure->transient);
    }

    public function test_uploads_a_file_creating_the_folders_it_needs_and_checking_the_size(): void
    {
        $local = $this->workspace.DIRECTORY_SEPARATOR.'page.jpg';
        file_put_contents($local, $contents = random_bytes(1200));

        $stored = $this->store->upload($local, '2025/01/07/16/39/53/8DB1E6AE.jpg');

        $this->assertSame(1200, $stored);
        $this->assertSame($contents, file_get_contents($this->root.str_replace('/', DIRECTORY_SEPARATOR, '/2025/01/07/16/39/53/8DB1E6AE.jpg')));
    }

    public function test_an_upload_of_a_file_that_is_not_there_is_a_failure(): void
    {
        $failure = $this->failureOf(fn () => $this->store->upload($this->workspace.DIRECTORY_SEPARATOR.'gone.jpg', '2025/page.jpg'));

        $this->assertFalse($failure->transient);
        $this->assertFileDoesNotExist($this->root.DIRECTORY_SEPARATOR.'2025');
    }

    public function test_reports_the_size_of_a_file_and_nothing_for_one_that_is_gone(): void
    {
        $this->write('2025/page.jpg', str_repeat('x', 77));

        $this->assertSame(77, $this->store->size('2025/page.jpg'));
        $this->assertNull($this->store->size('2025/other.jpg'));
    }

    public function test_deletes_a_file_and_accepts_one_that_is_already_gone(): void
    {
        $this->write('2025/page.jpg', 'a');

        $this->store->delete('2025/page.jpg');
        $this->store->delete('2025/page.jpg');

        $this->assertFileDoesNotExist($this->root.str_replace('/', DIRECTORY_SEPARATOR, '/2025/page.jpg'));
    }

    public function test_disconnecting_is_allowed_at_any_time(): void
    {
        $this->store->disconnect();
        $this->write('2025/page.jpg', 'a');

        $this->assertSame(['page.jpg'], $this->store->list('2025'));
    }

    private function write(string $path, string $contents): void
    {
        $file = $this->root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        File::ensureDirectoryExists(dirname($file));
        file_put_contents($file, $contents);
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
