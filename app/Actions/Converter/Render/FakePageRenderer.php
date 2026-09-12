<?php

namespace App\Actions\Converter\Render;

use Illuminate\Support\Facades\File;

/**
 * The renderer for the pipeline's tests. It writes $pages small but real JPEGs, named the way pdf2img
 * names them, or fails with $failure, and remembers what it was asked to render.
 */
class FakePageRenderer implements PageRenderer
{
    /**
     * A valid 8x8 grey JPEG: small enough to keep here, real enough for a thumbnailer to read it.
     */
    public const string JPEG = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAA0JCgsKCA0LCgsODg0PEyAVExISEyccHhcgLikxMC4pLSwzOko+MzZGN'
        .'ywtQFdBRkxOUlNSMj5aYVpQYEpRUk//wAALCAAIAAgBAREA/8QAFAABAAAAAAAAAAAAAAAAAAAAAP/EABQQAQAAAAAAAAAAAAAAAAAAA'
        .'AD/2gAIAQEAAD8AP//Z';

    /**
     * @var list<array{pdf: string, directory: string}>
     */
    public array $renders = [];

    public function __construct(
        public int $pages = 3,
        public ?RenderFailed $failure = null,
    ) {}

    /**
     * @return list<string>
     *
     * @throws RenderFailed
     */
    public function render(string $pdfPath, string $outputDirectory): array
    {
        $this->renders[] = ['pdf' => $pdfPath, 'directory' => $outputDirectory];

        if ($this->failure !== null) {
            throw $this->failure;
        }

        if ($this->pages < 1) {
            throw new RenderFailed('pdf2img reported success but wrote no page image.', exitCode: 0, retryable: false);
        }

        File::ensureDirectoryExists($outputDirectory);

        // Pdf2ImgRenderer empties the folder before it renders, because pdf2img leaves the pages of a
        // shorter or half finished earlier attempt behind; a fake that keeps them would let a caller
        // pass a test the real renderer fails.
        foreach (File::glob($outputDirectory.DIRECTORY_SEPARATOR.'page*.jpg') as $stale) {
            File::delete($stale);
        }

        $bytes = (string) base64_decode(self::JPEG, true);
        $pages = [];

        for ($page = 1; $page <= $this->pages; $page++) {
            // pdf2img leaves the number out when the PDF has a single page.
            $name = $this->pages === 1 ? 'page.jpg' : sprintf('page%04d.jpg', $page);
            $path = $outputDirectory.DIRECTORY_SEPARATOR.$name;
            File::put($path, $bytes);
            $pages[] = $path;
        }

        return $pages;
    }
}
