<?php

namespace Tests\Feature\Converter;

use App\Actions\Converter\Render\ImagickThumbnailer;
use Illuminate\Support\Facades\File;
use Imagick;
use RuntimeException;
use Tests\TestCase;

class ThumbnailerTest extends TestCase
{
    /**
     * The encoded test pages, by "<width>x<height>".
     *
     * @var array<string, string>
     */
    private static array $pages = [];

    private string $directory;

    private string $page;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'pdf2img-thumb-'.uniqid();
        $this->page = $this->directory.DIRECTORY_SEPARATOR.'page0001.jpg';

        File::ensureDirectoryExists($this->directory);
        $this->writeLargePage($this->page, 2400, 3200);

        config(['converter.thumbnail.width' => 120, 'converter.thumbnail.height' => 160, 'converter.thumbnail.quality' => 82]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_squeezes_a_large_page_into_the_archives_120x160_box(): void
    {
        $thumbnail = (new ImagickThumbnailer)->make($this->page);

        $this->assertJpeg($thumbnail, 120, 160);
        $this->assertNotSame($this->placeholder(), $thumbnail);
    }

    public function test_the_page_is_decoded_with_room_to_resize_from(): void
    {
        // libjpeg can only halve repeatedly, and it stops at the smallest step that still covers what
        // it was asked for. Asked for the thumbnail's own 120x160, a 1667x1250 page arrives as 417x313
        // and most of its detail is gone before the resize starts: measured against a faithful
        // full-decode downscale, that thumbnail was 0.0193 out where the same page asked for 480x640
        // is 0.0107. A page at 300 dpi is past libjpeg's floor either way, so this costs nothing where
        // it changes nothing.
        $thumbnailer = new class extends ImagickThumbnailer
        {
            public function hint(): string
            {
                return $this->decodeHint();
            }
        };

        $this->assertSame('480x640', $thumbnailer->hint());
    }

    public function test_it_keeps_the_box_when_the_page_is_wider_than_it_is_high(): void
    {
        $wide = $this->directory.DIRECTORY_SEPARATOR.'wide.jpg';
        $this->writeLargePage($wide, 1600, 400);

        // The archive's 140 million thumbnails were made by GetThumbnailImage(120, 160), which ignores
        // the aspect ratio; a page that keeps it would not look like the rest of the archive.
        $this->assertJpeg((new ImagickThumbnailer)->make($wide), 120, 160);
    }

    public function test_it_makes_the_thumbnail_with_gd_when_imagick_cannot(): void
    {
        $thumbnailer = new class extends ImagickThumbnailer
        {
            protected function withImagick(string $imagePath): ?string
            {
                return null;
            }
        };

        $thumbnail = $thumbnailer->make($this->page);

        $this->assertJpeg($thumbnail, 120, 160);
        $this->assertNotSame($this->placeholder(), $thumbnail);
    }

    public function test_it_returns_the_placeholder_for_a_page_that_is_not_an_image(): void
    {
        $garbage = $this->directory.DIRECTORY_SEPARATOR.'garbage.jpg';
        File::put($garbage, random_bytes(4096));

        $thumbnail = (new ImagickThumbnailer)->make($garbage);

        $this->assertJpeg($thumbnail, 120, 160);
        $this->assertSame($this->placeholder(), $thumbnail);
    }

    public function test_it_returns_the_placeholder_for_a_truncated_page(): void
    {
        $truncated = $this->directory.DIRECTORY_SEPARATOR.'truncated.jpg';
        File::put($truncated, substr(File::get($this->page), 0, 400));

        // Whatever the decoders make of half a JPEG, the page gets a thumbnail of the right size and
        // the conversion goes on: the old pipeline threw here and lost 685 documents.
        $this->assertJpeg((new ImagickThumbnailer)->make($truncated), 120, 160);
    }

    public function test_it_returns_the_placeholder_for_an_empty_page(): void
    {
        $empty = $this->directory.DIRECTORY_SEPARATOR.'empty.jpg';
        File::put($empty, '');

        $this->assertSame($this->placeholder(), (new ImagickThumbnailer)->make($empty));
    }

    public function test_it_returns_the_placeholder_when_a_backend_throws_out_of_the_decoder(): void
    {
        // The decoders catch their own failures, but the logging of one of them can fail as well (a
        // full or unwritable log disk) and that exception would end the whole content, which is how
        // the old pipeline lost 685 documents. Nothing gets out of make().
        $thumbnailer = new class extends ImagickThumbnailer
        {
            protected function withImagick(string $imagePath): ?string
            {
                throw new RuntimeException('the log disk is full');
            }

            protected function withGd(string $imagePath): ?string
            {
                throw new RuntimeException('the log disk is full');
            }
        };

        $this->assertSame($this->placeholder(), $thumbnailer->make($this->page));
    }

    public function test_it_returns_the_placeholder_for_a_page_that_is_not_there(): void
    {
        $thumbnail = (new ImagickThumbnailer)->make($this->directory.DIRECTORY_SEPARATOR.'gone.jpg');

        $this->assertSame($this->placeholder(), $thumbnail);
    }

    /**
     * The placeholder, recognised by what the thumbnailer returns for a file nothing can read.
     */
    private function placeholder(): string
    {
        $nothing = $this->directory.DIRECTORY_SEPARATOR.'nothing.jpg';
        File::put($nothing, '');

        return (new ImagickThumbnailer)->make($nothing);
    }

    private function assertJpeg(string $thumbnail, int $width, int $height): void
    {
        $size = getimagesizefromstring($thumbnail);

        $this->assertIsArray($size, 'The thumbnail is not a readable image.');
        $this->assertSame('image/jpeg', $size['mime']);
        $this->assertSame([$width, $height], [$size[0], $size[1]]);
    }

    /**
     * A page of the size pdf2img produces at 300 dpi, with content that does not compress to nothing.
     * Encoding it takes long enough to be worth keeping for the whole test class.
     */
    private function writeLargePage(string $path, int $width, int $height): void
    {
        $key = "{$width}x{$height}";

        if (isset(self::$pages[$key])) {
            File::put($path, self::$pages[$key]);

            return;
        }

        $this->encodeLargePage($path, $width, $height);
        self::$pages[$key] = File::get($path);
    }

    private function encodeLargePage(string $path, int $width, int $height): void
    {
        if (extension_loaded('imagick')) {
            $page = new Imagick;
            $page->newPseudoImage($width, $height, 'gradient:black-white');
            $page->setImageFormat('jpeg');
            $page->writeImage($path);
            $page->clear();

            return;
        }

        $page = imagecreatetruecolor($width, $height);

        for ($row = 0; $row < $height; $row += 4) {
            $grey = (int) (255 * $row / $height);
            imagefilledrectangle($page, 0, $row, $width, $row + 3, imagecolorallocate($page, $grey, $grey, $grey));
        }

        imagejpeg($page, $path, 85);
        imagedestroy($page);
    }
}
