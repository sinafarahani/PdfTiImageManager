<?php

namespace App\Actions\Converter\Pipeline;

/**
 * The step a conversion reached. It replaces the old pipeline's breadcrumb string (" 1 , 2 , 3 ,"),
 * which was the only clue a failed content left behind.
 */
enum Stage: string
{
    case Reserve = 'reserve';
    case Metadata = 'metadata';
    case Download = 'download';
    case Render = 'render';
    case Thumbnail = 'thumbnail';
    case Write = 'write';
    case Upload = 'upload';
    case Finish = 'finish';
    case Cleanup = 'cleanup';

    public function label(): string
    {
        return match ($this) {
            self::Reserve => 'taking the content',
            self::Metadata => 'reading the content in the archive',
            self::Download => 'downloading the PDF',
            self::Render => 'rendering the pages',
            self::Thumbnail => 'making thumbnails',
            self::Write => 'writing the page rows',
            self::Upload => 'uploading the images',
            self::Finish => 'marking the content converted',
            self::Cleanup => 'cleaning up',
        };
    }
}
