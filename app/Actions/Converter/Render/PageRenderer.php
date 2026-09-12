<?php

namespace App\Actions\Converter\Render;

/**
 * Turns a PDF into one image file per page by running pdf2img.
 */
interface PageRenderer
{
    /**
     * Renders every page of $pdfPath into $outputDirectory and returns the page files in page order.
     *
     * A run that produces no pages is a failure, not an empty result: the previous pipeline ignored
     * pdf2img's exit code, found no images, and still marked the document converted.
     *
     * @return list<string> absolute paths, page 1 first
     *
     * @throws RenderFailed
     */
    public function render(string $pdfPath, string $outputDirectory): array;
}
