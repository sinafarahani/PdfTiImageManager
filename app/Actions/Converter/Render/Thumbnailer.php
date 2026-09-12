<?php

namespace App\Actions\Converter\Render;

/**
 * Makes the small preview image the archive stores next to every page.
 */
interface Thumbnailer
{
    /**
     * Returns the JPEG bytes of the thumbnail for $imagePath.
     *
     * This must never throw. The previous pipeline's thumbnail call failed on large pages ("out of
     * memory", "overflow") and its fallback read a file that did not exist, which killed the whole
     * content; 685 documents were lost that way. A thumbnail that cannot be made is a placeholder,
     * never an exception.
     */
    public function make(string $imagePath): string;
}
