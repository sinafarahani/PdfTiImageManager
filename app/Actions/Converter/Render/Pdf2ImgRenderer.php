<?php

namespace App\Actions\Converter\Render;

use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Throwable;

/**
 * Renders a PDF with pdf2img, the replacement written for VeryPDF PDF To Image Converter 2.1.
 *
 * pdf2img is given one output file ("<directory>\page.jpg") and derives the page names from it:
 * "page0001.jpg", "page0002.jpg", ... and, for a PDF of a single page, "page.jpg" without a number.
 * Its exit code is what makes this pipeline possible: the old tool returned 0 for a damaged file, a
 * missing one and a password-protected one alike, so the bridge could not tell a run that rendered
 * nothing from one that worked.
 */
class Pdf2ImgRenderer implements PageRenderer
{
    /**
     * The archive stores page images as JPEG, and pdf2img picks the format from the -o extension.
     */
    private const string EXTENSION = 'jpg';

    /**
     * Base name given to pdf2img; it appends the page number to it.
     */
    private const string STEM = 'page';

    /**
     * Characters of stderr kept in a failure, so that a run with -v cannot fill the failure log.
     */
    private const int ERROR_OUTPUT_LIMIT = 4000;

    /**
     * Seconds a render may take when converter.render.timeout holds no usable number. A zero would
     * reach Symfony as "no timeout at all" and a negative one would make every render fail, so the
     * documented default is used instead of the broken setting.
     */
    private const int DEFAULT_TIMEOUT = 1800;

    /**
     * Seconds added to the configured timeout before the process is killed. pdf2img has its own
     * -totaltimeout (1800 by default, the same number as ours), and it ends the run with exit 5,
     * which names the cause and lets it stop its render workers itself. The kill is only for a tool
     * that does not honour its own limit, so it has to come second, not at the same instant.
     */
    private const int TIMEOUT_GRACE = 30;

    /**
     * Bytes of the PDF searched for the "%PDF-" header. PDFium and qpdf accept a header that starts
     * a little way into the file, so the whole first block counts as "looks like a PDF".
     */
    private const int HEADER_SEARCH_BYTES = 1024;

    /**
     * Bytes of the end of the file searched for the %%EOF a whole PDF finishes with. The marker is the
     * last line, but a producer may leave padding or a little junk after it.
     */
    private const int TAIL_SEARCH_BYTES = 2048;

    /**
     * @return list<string>
     *
     * @throws RenderFailed
     */
    public function render(string $pdfPath, string $outputDirectory): array
    {
        // A PDF that is not there is the download's problem and is worth another attempt, while
        // pdf2img would report it as exit 2, the code for a file that will never render.
        if (! File::isFile($pdfPath) || File::size($pdfPath) === 0) {
            throw new RenderFailed("There is no PDF to render at {$pdfPath}.", retryable: true);
        }

        File::ensureDirectoryExists($outputDirectory);
        $directory = realpath($outputDirectory);

        if ($directory === false) {
            throw new RenderFailed("The output directory {$outputDirectory} could not be created.", retryable: true);
        }

        $this->deletePreviousPages($directory);

        // Whether the file carries a PDF header decides how exit 2 is read further down: the tool
        // reports "not found, not a PDF, or no pages" with the same code, and a file that is not a
        // PDF at all is a broken download. It is not refused here, because pdf2img repairs a damaged
        // header with qpdf and those documents do render.
        $looksLikePdf = $this->looksLikePdf($pdfPath);
        $timeout = $this->timeout();
        $errorOutput = '';

        try {
            // stderr is collected as it arrives: a run that is killed by the timeout leaves no result
            // to read it from afterwards, and its last lines are what the failure log needs.
            $result = Process::timeout($timeout + self::TIMEOUT_GRACE)->run(
                $this->command($pdfPath, $directory),
                function (string $type, string $buffer) use (&$errorOutput): void {
                    if ($type === SymfonyProcess::ERR) {
                        $errorOutput .= $buffer;
                    }
                }
            );
        } catch (ProcessTimedOutException) {
            $killedAfter = $timeout + self::TIMEOUT_GRACE;

            throw new RenderFailed(
                "pdf2img did not finish within {$killedAfter} seconds and was killed.",
                errorOutput: $this->shorten($errorOutput),
                retryable: true,
            );
        } catch (Throwable $exception) {
            // Anything else here is the machine (no process could be started, the working directory is
            // gone); the pipeline only knows RenderFailed, so it is reported as one.
            throw new RenderFailed('pdf2img could not be run: '.$exception->getMessage(), retryable: true);
        }

        if ($result->failed()) {
            throw $this->failure($result->exitCode(), $this->shorten($result->errorOutput()), $looksLikePdf, $this->describeFile($pdfPath));
        }

        return $this->pages($directory);
    }

    /**
     * Seconds pdf2img may take, from the configuration.
     *
     * A zero (an empty or non-numeric CONVERTER_RENDER_TIMEOUT reaches the config as one) would tell
     * Symfony to wait forever, and a hung render would hold the worker until someone noticed.
     */
    private function timeout(): int
    {
        $timeout = (int) config('converter.render.timeout');

        if ($timeout > 0) {
            return $timeout;
        }

        Log::warning("converter.render.timeout is {$timeout}; pdf2img is given ".self::DEFAULT_TIMEOUT.' seconds instead.');

        return self::DEFAULT_TIMEOUT;
    }

    /**
     * Whether the file carries a PDF header in its first block.
     */
    private function looksLikePdf(string $pdfPath): bool
    {
        $head = @file_get_contents($pdfPath, length: self::HEADER_SEARCH_BYTES);

        return is_string($head) && str_contains($head, '%PDF-');
    }

    /**
     * How the file itself looks, for the failure message: its size, and whether it ends the way a
     * whole PDF ends.
     *
     * A header alone says nothing - every PDF opens with "%PDF-1.x" and a line of binary, including
     * the ones that were cut off half way. The end is what tells them apart: a complete document
     * finishes with a startxref and %%EOF, so a file that has a header and no ending is a damaged
     * copy on the site rather than a document this tool cannot read, and that is worth saying.
     */
    private function describeFile(string $pdfPath): string
    {
        clearstatcache(true, $pdfPath);
        $bytes = @filesize($pdfPath);

        if ($bytes === false) {
            return basename($pdfPath);
        }

        $tail = @file_get_contents($pdfPath, offset: max(0, $bytes - self::TAIL_SEARCH_BYTES));
        $whole = is_string($tail) && str_contains($tail, '%%EOF');

        return sprintf(
            '%s, %s bytes%s',
            basename($pdfPath),
            number_format($bytes),
            $whole ? '' : ', and it has no %%EOF: the copy on the file store is cut short',
        );
    }

    /**
     * Deletes the page images of an earlier attempt.
     *
     * pdf2img overwrites the images it writes and removes nothing else, so a folder that already
     * holds pages keeps every one this run does not reach: an attempt that was killed half way, or a
     * document that has since become shorter, would otherwise be read back as a complete render and
     * the leftovers would be stored as its last pages.
     *
     * @throws RenderFailed
     */
    private function deletePreviousPages(string $directory): void
    {
        foreach (File::files($directory) as $file) {
            if ($this->pageNumber($file->getFilename()) === false) {
                continue;
            }

            // A page that cannot be deleted (another worker still has it open) would be counted as
            // part of this render, so the run does not start at all.
            if (! @unlink($file->getPathname())) {
                throw new RenderFailed(
                    "The page image {$file->getFilename()} of an earlier attempt could not be deleted.",
                    retryable: true,
                );
            }
        }
    }

    /**
     * The page number in a file name pdf2img wrote, "" for the single page it leaves unnumbered, or
     * false when the name is not one of its images.
     */
    private function pageNumber(string $filename): string|false
    {
        $pattern = '/^'.self::STEM.'(\d*)\.'.self::EXTENSION.'$/i';

        return preg_match($pattern, $filename, $match) === 1 ? $match[1] : false;
    }

    /**
     * The command line: the binary, the configured arguments, then the input and the output file.
     *
     * @return list<string>
     */
    private function command(string $pdfPath, string $directory): array
    {
        return [
            (string) config('converter.render.binary'),
            ...$this->arguments(),
            '-i', $pdfPath,
            '-o', $directory.DIRECTORY_SEPARATOR.self::STEM.'.'.self::EXTENSION,
        ];
    }

    /**
     * The configured arguments as separate words. They are passed as an array rather than as one
     * string, so that the process runs without a shell and a quoted argument keeps its spaces.
     *
     * @return list<string>
     */
    private function arguments(): array
    {
        $arguments = config('converter.render.arguments');

        if (is_array($arguments)) {
            return array_values(array_map(strval(...), $arguments));
        }

        $words = str_getcsv((string) $arguments, ' ', '"', '');

        return array_values(array_filter(
            array_map(fn (?string $word): string => (string) $word, $words),
            fn (string $word): bool => $word !== '',
        ));
    }

    /**
     * The rendered pages, page 1 first.
     *
     * @return list<string>
     *
     * @throws RenderFailed
     */
    private function pages(string $directory): array
    {
        /** @var array<int, string> $numbered */
        $numbered = [];
        $unnumbered = null;
        $others = [];

        foreach (File::files($directory) as $file) {
            $number = $this->pageNumber($file->getFilename());

            if ($number === false) {
                if (strcasecmp((string) $file->getExtension(), self::EXTENSION) === 0) {
                    $others[] = $file->getFilename();
                }

                continue;
            }

            // An empty image is a page that was not written to the end: the disk, not the document.
            if ($file->getSize() === 0) {
                throw new RenderFailed("pdf2img left an empty image behind, {$file->getFilename()}.", retryable: true);
            }

            if ($number === '') {
                $unnumbered = $file->getPathname();

                continue;
            }

            $page = (int) $number;

            // Two names for one page (a "page1.jpg" next to a "page0001.jpg") in a folder emptied
            // before the run: which of the two holds the page cannot be known, and asking again
            // produces the same pair.
            if (isset($numbered[$page])) {
                throw new RenderFailed(
                    "There are two images for page {$page} in {$directory}.",
                    exitCode: 0,
                    retryable: false,
                );
            }

            $numbered[$page] = $file->getPathname();
        }

        // The number is left out only when pdf2img writes exactly one page, so both forms in a folder
        // emptied before the run mean it wrote images this code cannot count: none of them are used.
        if ($numbered !== [] && $unnumbered !== null) {
            throw new RenderFailed(
                "pdf2img wrote a numbered page and the unnumbered {$unnumbered} in one run.",
                exitCode: 0,
                retryable: false,
            );
        }

        if ($unnumbered !== null) {
            return [$unnumbered];
        }

        if ($numbered === []) {
            // Images under another name mean pdf2img numbers its pages differently than we read them
            // (an "AddFileNameSuffix" in its pdf2img.ini that is not just a "%d"), which is an
            // installation to correct and not a document to retry, so the names are in the message.
            $found = $others === [] ? '' : ' The folder holds '.implode(', ', array_slice($others, 0, 5)).'.';

            // The old bridge accepted this silently and marked the content converted; 10,601 documents
            // in the archive carry a "rendered" flag and have no pages at all.
            throw new RenderFailed('pdf2img reported success but wrote no page image.'.$found, exitCode: 0, retryable: false);
        }

        // By number, not sort(): with an unpadded page suffix "page10.jpg" sorts before "page2.jpg".
        ksort($numbered, SORT_NUMERIC);

        $expected = 1;

        foreach (array_keys($numbered) as $page) {
            if ($page !== $expected) {
                throw new RenderFailed(
                    "pdf2img reported success but page {$expected} is missing; the next image is page {$page}.",
                    exitCode: 0,
                    retryable: false,
                );
            }

            $expected++;
        }

        return array_values($numbered);
    }

    /**
     * The failure for an exit code of pdf2img (see its README). $retryable separates the machine's
     * bad moments from the documents that will never render, whatever we do.
     */
    private function failure(?int $exitCode, string $errorOutput, bool $looksLikePdf, string $file): RenderFailed
    {
        if ($exitCode === null) {
            // The process never reached a state that has an exit code, which is the machine's problem.
            return new RenderFailed("pdf2img ended without an exit code while rendering {$file}.", null, $errorOutput, true);
        }

        [$reason, $retryable] = match ($exitCode) {
            1 => ['pdf2img did not accept the command line', false],
            // Exit 2 is "not found, not a PDF, or no pages". A file without a PDF header is a broken
            // download and worth another attempt; one with a header is a document that will not
            // render, whoever asks again. pdf2img repairs what can be repaired before it says this.
            2 => $looksLikePdf
                ? ['the file is not a readable PDF', false]
                : ['the downloaded file is not a PDF at all', true],
            3 => ['a page could not be rendered', false],
            4 => ['an image could not be written', true],
            5 => ['pdf2img ran into its own time limit', true],
            6 => ['pdf2img does not know the output format', false],
            7 => ['the PDF is password protected', false],
            9 => ['pdf2img failed internally, a render process crashed or pdfium is missing', true],
            default => ['pdf2img failed for an unknown reason', true],
        };

        // The file is named here as well as by the pipeline: this message is what reaches the log, and
        // the name is the source row's own id, which is enough to find the document on the site.
        return new RenderFailed("pdf2img exited with {$exitCode}: {$reason} ({$file}).", $exitCode, $errorOutput, $retryable);
    }

    /**
     * The head of the error output; pdf2img prints the cause first and a line per failed page after it.
     */
    private function shorten(string $errorOutput): string
    {
        $errorOutput = trim($errorOutput);

        return strlen($errorOutput) <= self::ERROR_OUTPUT_LIMIT
            ? $errorOutput
            : substr($errorOutput, 0, self::ERROR_OUTPUT_LIMIT).' ...';
    }
}
