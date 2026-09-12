<?php

namespace App\Actions\Converter\Render;

/**
 * The thumbnailer for the pipeline's tests: it returns the same small JPEG for every page and
 * remembers which pages it was given.
 */
class FakeThumbnailer implements Thumbnailer
{
    /**
     * @var list<string>
     */
    public array $images = [];

    /**
     * $thumbnail replaces the JPEG that is returned by default, for a test that needs to recognise
     * the bytes again further down the pipeline.
     */
    public function __construct(
        public ?string $thumbnail = null,
    ) {}

    public function make(string $imagePath): string
    {
        $this->images[] = $imagePath;

        return $this->thumbnail ?? (string) base64_decode(FakePageRenderer::JPEG, true);
    }
}
