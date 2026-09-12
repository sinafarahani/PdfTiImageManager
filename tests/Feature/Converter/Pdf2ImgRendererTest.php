<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Render\FakePageRenderer;
use App\Actions\Converter\Render\Pdf2ImgRenderer;
use App\Actions\Converter\Render\RenderFailed;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyTimedOut;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

class Pdf2ImgRendererTest extends TestCase
{
    private string $directory;

    private string $pdf;

    /**
     * The timeout pdf2img was given, recorded by the fake.
     */
    private ?int $timeout = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-render-'.uniqid();
        $this->pdf = $this->directory.DIRECTORY_SEPARATOR.'source.pdf';

        File::ensureDirectoryExists($this->directory);
        File::put($this->pdf, '%PDF-1.4 not read by the fake');

        config([
            'converter.render.binary' => 'C:\pdfToImg\bin\pdf2img.exe',
            'converter.render.arguments' => '-c lzw -r 300',
            'converter.render.timeout' => 1800,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_returns_the_pages_in_page_order(): void
    {
        // Unpadded numbers, which an "AddFileNameSuffix = %d" in pdf2img.ini produces: sorted as text,
        // page10 would come before page2.
        $this->fakePdf2Img(array_map(fn (int $page): string => "page{$page}.jpg", range(1, 11)));

        $pages = (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        $this->assertCount(11, $pages);
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'page1.jpg', $pages[0]);
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'page9.jpg', $pages[8]);
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'page10.jpg', $pages[9]);
        $this->assertSame($this->directory.DIRECTORY_SEPARATOR.'page11.jpg', $pages[10]);
    }

    public function test_it_returns_the_page_of_a_single_page_pdf_which_pdf2img_does_not_number(): void
    {
        $this->fakePdf2Img(['page.jpg']);

        $pages = (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        $this->assertSame([$this->directory.DIRECTORY_SEPARATOR.'page.jpg'], $pages);
    }

    public function test_it_runs_pdf2img_with_the_configured_arguments_and_timeout(): void
    {
        $this->fakePdf2Img(['page0001.jpg', 'page0002.jpg']);

        (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === [
            'C:\pdfToImg\bin\pdf2img.exe',
            '-c', 'lzw', '-r', '300',
            '-i', $this->pdf,
            '-o', $this->directory.DIRECTORY_SEPARATOR.'page.jpg',
        ]);

        // Laravel kills a process after 60 seconds by default; conversions of over 200 seconds are
        // normal, so an unset timeout would end every real job. The kill comes a little after the
        // configured limit, which is also pdf2img's own -totaltimeout: its exit 5 names the cause and
        // lets it stop its render workers, so it has to get there first.
        $this->assertSame(1830, $this->timeout);
    }

    public function test_it_gives_pdf2img_the_default_timeout_when_the_configured_one_is_unusable(): void
    {
        // An empty or non-numeric CONVERTER_RENDER_TIMEOUT reaches the config as 0, which Symfony
        // reads as "wait forever": a hung render would hold the worker until someone noticed.
        config(['converter.render.timeout' => 0]);
        $this->fakePdf2Img(['page0001.jpg']);

        (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        $this->assertSame(1830, $this->timeout);
    }

    public function test_it_deletes_the_pages_of_an_earlier_attempt_before_it_renders(): void
    {
        // pdf2img overwrites the images it writes and deletes nothing: after an attempt that was
        // killed half way, or on a document that has become shorter, the pages it does not reach stay
        // behind. Read back as this run's result they would be stored as the document's last pages.
        $stale = array_map(fn (int $page): string => sprintf('page%04d.jpg', $page), range(1, 17));

        foreach ($stale as $name) {
            File::put($this->directory.DIRECTORY_SEPARATOR.$name, base64_decode(FakePageRenderer::JPEG, true));
        }

        $this->fakePdf2Img(['page0001.jpg', 'page0002.jpg', 'page0003.jpg']);

        $pages = (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        $this->assertCount(3, $pages);
        $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'page0017.jpg');
    }

    public function test_it_deletes_the_unnumbered_page_of_an_earlier_attempt(): void
    {
        File::put($this->directory.DIRECTORY_SEPARATOR.'page.jpg', base64_decode(FakePageRenderer::JPEG, true));

        $this->fakePdf2Img(['page0001.jpg', 'page0002.jpg']);

        $pages = (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        $this->assertCount(2, $pages);
    }

    public function test_it_keeps_the_pdf_it_renders(): void
    {
        $this->fakePdf2Img(['page0001.jpg']);

        (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);

        // The source PDF lives next to the pages while a content is converted.
        $this->assertFileExists($this->pdf);
    }

    public function test_it_retries_a_download_that_is_not_a_pdf_at_all(): void
    {
        // pdf2img reports "not found, not a PDF, or no pages" with the same exit 2. A file without a
        // PDF header never came out of the archive whole, and the next download may.
        File::put($this->pdf, 'html>500 Internal Server Error');
        $this->fakePdf2Img([], 2, 'pdf2img: error: not a valid PDF (format error)');

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('Exit code 2 must be a failure.');
        } catch (RenderFailed $failure) {
            $this->assertTrue($failure->retryable);
        }
    }

    public function test_it_names_the_images_it_could_not_count(): void
    {
        // A pdf2img.ini with an "AddFileNameSuffix" that is not just a "%d" renames every page; the
        // failure has to point at the installation instead of blaming the document.
        $this->fakePdf2Img(['page_0001.jpg', 'page_0002.jpg']);

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('Images this code cannot count must not be a success.');
        } catch (RenderFailed $failure) {
            $this->assertStringContainsString('page_0001.jpg', $failure->getMessage());
        }
    }

    public function test_it_fails_when_pdf2img_reports_success_without_writing_an_image(): void
    {
        $this->fakePdf2Img([]);

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('A run without images must not be a success.');
        } catch (RenderFailed $failure) {
            $this->assertStringContainsString('no page image', $failure->getMessage());
            $this->assertFalse($failure->retryable);
        }
    }

    public function test_it_fails_when_a_page_is_missing_between_the_images(): void
    {
        $this->fakePdf2Img(['page0001.jpg', 'page0002.jpg', 'page0004.jpg']);

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('A gap in the page numbers must not be a success.');
        } catch (RenderFailed $failure) {
            $this->assertStringContainsString('page 3 is missing', $failure->getMessage());
        }
    }

    /**
     * @return array<string, array{int, bool}>
     */
    public static function exitCodes(): array
    {
        return [
            'bad command line' => [1, false],
            'not a readable PDF' => [2, false],
            'a page could not be rendered' => [3, false],
            'an image could not be written' => [4, true],
            'pdf2img ran into its own time limit' => [5, true],
            'unknown output format' => [6, false],
            'password protected' => [7, false],
            'internal failure' => [9, true],
            'unknown code' => [42, true],
        ];
    }

    #[DataProvider('exitCodes')]
    public function test_it_maps_the_exit_code_of_pdf2img(int $exitCode, bool $retryable): void
    {
        $this->fakePdf2Img([], $exitCode, 'pdf2img: error: something is wrong');

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail("Exit code {$exitCode} must be a failure.");
        } catch (RenderFailed $failure) {
            $this->assertSame($exitCode, $failure->exitCode);
            $this->assertSame($retryable, $failure->retryable, "Exit code {$exitCode} is retryable: ".var_export($retryable, true));
            $this->assertSame('pdf2img: error: something is wrong', $failure->errorOutput);
        }
    }

    public function test_it_fails_retryably_when_pdf2img_runs_into_the_timeout(): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*' => fn (): ProcessTimedOutException => $this->timedOut()]);

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('A killed pdf2img must be a failure.');
        } catch (RenderFailed $failure) {
            $this->assertStringContainsString('1830 seconds', $failure->getMessage());
            $this->assertTrue($failure->retryable);
            $this->assertNull($failure->exitCode);
        }
    }

    public function test_it_does_not_run_pdf2img_when_the_pdf_was_not_downloaded(): void
    {
        Process::preventStrayProcesses();
        Process::fake();
        File::delete($this->pdf);

        try {
            (new Pdf2ImgRenderer)->render($this->pdf, $this->directory);
            $this->fail('A missing PDF must be a failure.');
        } catch (RenderFailed $failure) {
            // The download is worth repeating; pdf2img would call this exit 2, the code of a file that
            // will never render.
            $this->assertTrue($failure->retryable);
        }

        Process::assertNothingRan();
    }

    public function test_the_fake_renderer_writes_the_pages_the_real_one_would(): void
    {
        $fake = new FakePageRenderer(pages: 2);

        $pages = $fake->render($this->pdf, $this->directory);

        $this->assertSame([
            $this->directory.DIRECTORY_SEPARATOR.'page0001.jpg',
            $this->directory.DIRECTORY_SEPARATOR.'page0002.jpg',
        ], $pages);
        $this->assertSame('image/jpeg', getimagesize($pages[0])['mime']);

        // Like the real renderer, it does not leave the pages of an earlier render behind.
        $fake->pages = 1;

        $this->assertSame([$this->directory.DIRECTORY_SEPARATOR.'page.jpg'], $fake->render($this->pdf, $this->directory));
        $this->assertFileDoesNotExist($this->directory.DIRECTORY_SEPARATOR.'page0002.jpg');
        $this->assertCount(2, $fake->renders);
    }

    public function test_it_renders_a_real_pdf(): void
    {
        $binary = $this->realBinary();
        $samples = 'H:\projects\pdfToImg\server_test\samples';

        if ($binary === null || ! File::isFile($samples.DIRECTORY_SEPARATOR.'report.pdf')) {
            $this->markTestSkipped('pdf2img or the sample PDFs are not on this machine.');
        }

        config(['converter.render.binary' => $binary, 'converter.render.arguments' => '-r 72', 'converter.render.timeout' => 120]);
        $renderer = new Pdf2ImgRenderer;

        $pages = $renderer->render($samples.DIRECTORY_SEPARATOR.'report.pdf', $this->directory.DIRECTORY_SEPARATOR.'many');

        $this->assertGreaterThan(1, count($pages));
        $this->assertStringEndsWith('page0001.jpg', $pages[0]);

        foreach ($pages as $page) {
            $this->assertSame('image/jpeg', getimagesize($page)['mime']);
        }

        $single = $renderer->render($samples.DIRECTORY_SEPARATOR.'one.pdf', $this->directory.DIRECTORY_SEPARATOR.'one');

        $this->assertCount(1, $single);
        $this->assertStringEndsWith('page.jpg', $single[0]);

        // The real tool overwrites the images of a run and removes nothing else, so the folder of the
        // 17 page document still holds them: a shorter document rendered into it must not come back
        // with the pages of the longer one.
        $again = $renderer->render($samples.DIRECTORY_SEPARATOR.'one.pdf', $this->directory.DIRECTORY_SEPARATOR.'many');

        $this->assertCount(1, $again);
    }

    /**
     * Makes pdf2img write $names into the folder its -o points at and exit with $exitCode.
     *
     * @param  list<string>  $names
     */
    private function fakePdf2Img(array $names, int $exitCode = 0, string $errorOutput = ''): void
    {
        Process::preventStrayProcesses();
        Process::fake(['*' => function (PendingProcess $process) use ($names, $exitCode, $errorOutput) {
            $this->timeout = $process->timeout;

            /** @var list<string> $command */
            $command = $process->command;
            $directory = dirname($command[(int) array_search('-o', $command, true) + 1]);

            foreach ($names as $name) {
                File::put($directory.DIRECTORY_SEPARATOR.$name, base64_decode(FakePageRenderer::JPEG, true));
            }

            return Process::result(errorOutput: $errorOutput, exitCode: $exitCode);
        }]);
    }

    /**
     * The exception Laravel raises when it kills a process that ran too long.
     */
    private function timedOut(): ProcessTimedOutException
    {
        $process = new SymfonyProcess(['pdf2img']);
        $process->setTimeout(1800);

        return ProcessTimedOutException::make(
            new SymfonyTimedOut($process, SymfonyTimedOut::TYPE_GENERAL),
            new ProcessResult($process),
        );
    }

    /**
     * The pdf2img of this machine: the configured one, or the one built in the tool's own repository.
     */
    private function realBinary(): ?string
    {
        $candidates = [
            (string) config('converter.render.binary'),
            'H:\projects\pdfToImg\new\build\release\pdf2img.exe',
        ];

        foreach ($candidates as $candidate) {
            if (File::isFile($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
