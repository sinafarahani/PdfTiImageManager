<?php

namespace App\Actions\Converter\Render;

use GdImage;
use Illuminate\Support\Facades\Log;
use Imagick;
use Throwable;

/**
 * Makes the archive's thumbnail of a rendered page: Imagick first, GD second, and a placeholder that
 * cannot fail last.
 *
 * The geometry is the old pipeline's GetThumbnailImage(120, 160): the page is squeezed into an exact
 * box, its aspect ratio is not kept. It looks wrong next to the page, and it is what the archive's
 * 140 million existing thumbnails look like, so it stays.
 */
class ImagickThumbnailer implements Thumbnailer
{
    /**
     * Limits for ImageMagick's pixel cache, in bytes, and for a single read, in seconds. They are
     * process wide: a page that declares impossible dimensions fails inside Imagick instead of taking
     * the whole queue worker down with it.
     */
    private const int IMAGICK_MEMORY_BYTES = 268435456;

    private const int IMAGICK_MAP_BYTES = 536870912;

    private const int IMAGICK_AREA_BYTES = 134217728;

    private const int IMAGICK_SECONDS = 20;

    private const int IMAGICK_MAX_DIMENSION = 65536;

    /**
     * Pixels GD may decode, and the bytes one of them costs (a true colour pixel plus the decoder's
     * own buffers). GD does not fail when an allocation fails, it ends the process, so the size is
     * checked before the file is opened and not afterwards.
     */
    private const int GD_MAX_PIXELS = 40000000;

    private const float GD_BYTES_PER_PIXEL = 4.5;

    /**
     * A 120x160 grey JPEG, in the class itself. The old pipeline's fallback read a placeholder file
     * from disk that was not there, and the missing file took 685 documents with it.
     */
    private const string PLACEHOLDER = '/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAAoHBwgHBgoICAgLCgoLDhgQDg0NDh0VFhEYIx8lJCIfIiEmKzcvJik0KSEiMEExNDk7Pj4+JS5ESU'
        .'M8SDc9Pjv/wAALCACgAHgBAREA/8QAFwABAQEBAAAAAAAAAAAAAAAAAAIDB//EACgQAQABAgMJAQEAAwAAAAAAAAADAQIEMzQTFFJTcoGSobER'
        .'IUFRYf/aAAgBAQAAPwDpWHw8UkFt99v7dX9/a/tf9tN0g4PdTdIOD3U3SDg91N0g4PdTdIOD3U3SDg91N0g4PdTdIOD3U3SDg91N0g4PdTdIOD'
        .'3U3SDg91N0g4PdUyYWG2K+6ln9pbWtP7VWE01nf62AAAARNkSdNUYTTWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVGE01nf62AAAARNkSdNUYT'
        .'TWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVGE01nf62AAAARNkSdNUYTTWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVGE01nf62AAAARNkSdNU'
        .'YTTWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVGE01nf62AAAARNkSdNUYTTWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVGE01nf62AAAARNkSd'
        .'NUYTTWd/rYAAABE2RJ01RhNNZ3+tgAAAETZEnTVlhZI7cPbS6+2lf7/K1/6120XMs8qG2i5lnlQ20XMs8qG2i5lnlQ20XMs8qG2i5lnlQ20XMs'
        .'8qG2i5lnlQ20XMs8qG2i5lnlQ20XMs8qG2i5lnlQ20XMs8qIlljrDfSklta1tr/l//2Q==';

    private readonly int $width;

    private readonly int $height;

    private readonly int $quality;

    public function __construct()
    {
        $this->width = (int) config('converter.thumbnail.width');
        $this->height = (int) config('converter.thumbnail.height');
        $this->quality = (int) config('converter.thumbnail.quality');
    }

    public function make(string $imagePath): string
    {
        try {
            return $this->withImagick($imagePath)
                ?? $this->withGd($imagePath)
                ?? $this->placeholder();
        } catch (Throwable $exception) {
            // Nothing below is expected to throw, and this is the guard that makes that promise hold:
            // the two decoders catch their own failures, but the logging of one of them can fail as
            // well (a full or unwritable log disk), and an exception from here would end the whole
            // content, which is exactly how the old pipeline lost 685 documents.
            try {
                report($exception);
            } catch (Throwable) {
                // Not even the report of a failed thumbnail may end a conversion.
            }

            return $this->placeholder();
        }
    }

    /**
     * The thumbnail from Imagick, or null when it could not be made.
     *
     * The "jpeg:size" hint lets libjpeg shrink the page while it decodes it: a 4724x6850 page arrives
     * as 591x857 and costs about 2 MB and 30 ms, instead of the 136 MB a full decode would hold.
     */
    protected function withImagick(string $imagePath): ?string
    {
        if (! extension_loaded('imagick')) {
            return null;
        }

        $imagick = null;

        try {
            $this->limitImagickResources();

            $imagick = new Imagick;
            $imagick->setOption('jpeg:size', $this->width.'x'.$this->height);
            $imagick->readImage($imagePath);
            $imagick->thumbnailImage($this->width, $this->height, false);
            $imagick->setImageFormat('jpeg');
            $imagick->setImageCompressionQuality($this->quality);

            // The archive's thumbnails are about 4 KB; the page's colour profile and EXIF would be
            // several times the image itself.
            $imagick->stripImage();

            $thumbnail = $imagick->getImageBlob();

            return $thumbnail !== '' ? $thumbnail : null;
        } catch (Throwable $exception) {
            Log::warning("Imagick could not make the thumbnail of {$imagePath}: {$exception->getMessage()}");

            return null;
        } finally {
            $imagick?->clear();
        }
    }

    /**
     * The thumbnail from GD, or null when the page is too big to decode safely or GD does not read it.
     */
    protected function withGd(string $imagePath): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }

        $size = @getimagesize($imagePath);

        if ($size === false || $size[0] < 1 || $size[1] < 1 || ! $this->gdCanDecode($size[0], $size[1])) {
            return null;
        }

        $source = null;
        $thumbnail = null;
        $buffers = ob_get_level();

        try {
            $source = match ($size[2]) {
                IMAGETYPE_JPEG => @imagecreatefromjpeg($imagePath),
                IMAGETYPE_PNG => @imagecreatefrompng($imagePath),
                IMAGETYPE_GIF => @imagecreatefromgif($imagePath),
                IMAGETYPE_WEBP => @imagecreatefromwebp($imagePath),
                IMAGETYPE_BMP => @imagecreatefrombmp($imagePath),
                default => null,
            };

            if (! $source instanceof GdImage) {
                return null;
            }

            $thumbnail = imagecreatetruecolor($this->width, $this->height);
            imagecopyresampled($thumbnail, $source, 0, 0, 0, 0, $this->width, $this->height, $size[0], $size[1]);

            ob_start();
            imagejpeg($thumbnail, null, $this->quality);
            $blob = (string) ob_get_clean();

            return $blob !== '' ? $blob : null;
        } catch (Throwable $exception) {
            Log::warning("GD could not make the thumbnail of {$imagePath}: {$exception->getMessage()}");

            return null;
        } finally {
            // The worker runs for hours; a buffer left open by a failed imagejpeg() would collect
            // everything printed after it.
            while (ob_get_level() > $buffers) {
                ob_end_clean();
            }

            if ($source instanceof GdImage) {
                imagedestroy($source);
            }

            if ($thumbnail instanceof GdImage) {
                imagedestroy($thumbnail);
            }
        }
    }

    /**
     * The thumbnail every page gets when nothing else worked. A content with a placeholder is still a
     * converted content; the failure is in the log, not in an exception.
     */
    protected function placeholder(): string
    {
        return (string) base64_decode(self::PLACEHOLDER, true);
    }

    /**
     * Whether decoding an image of this size can be afforded. The estimate is deliberately rough and
     * on the pessimistic side: guessing too low costs a placeholder, guessing too high costs the
     * worker.
     */
    private function gdCanDecode(int $width, int $height): bool
    {
        $pixels = $width * $height;

        if ($pixels > self::GD_MAX_PIXELS) {
            return false;
        }

        $limit = $this->memoryLimit();

        return $limit === null || $pixels * self::GD_BYTES_PER_PIXEL < $limit - memory_get_usage(true);
    }

    /**
     * The process' memory limit in bytes, or null when it has none.
     */
    private function memoryLimit(): ?int
    {
        $limit = trim((string) ini_get('memory_limit'));

        if ($limit === '' || $limit === '-1') {
            return null;
        }

        $bytes = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $bytes * 1024 * 1024 * 1024,
            'm' => $bytes * 1024 * 1024,
            'k' => $bytes * 1024,
            default => $bytes,
        };
    }

    private function limitImagickResources(): void
    {
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MEMORY, self::IMAGICK_MEMORY_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_MAP, self::IMAGICK_MAP_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_AREA, self::IMAGICK_AREA_BYTES);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_TIME, self::IMAGICK_SECONDS);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_WIDTH, self::IMAGICK_MAX_DIMENSION);
        Imagick::setResourceLimit(Imagick::RESOURCETYPE_HEIGHT, self::IMAGICK_MAX_DIMENSION);
    }
}
