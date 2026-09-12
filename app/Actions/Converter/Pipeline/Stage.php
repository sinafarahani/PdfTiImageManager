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

    /**
     * The archive names a source file that is not on the file store any more. Its own outcome, and
     * never retried: the row is stale, and no number of attempts will bring the file back. Keeping it
     * apart from a download that failed is what lets the two be counted - and retried - separately.
     */
    case Missing = 'missing';

    /**
     * The content belongs to a profile named in converter.skip_profiles, so it is not converted at
     * all. Not a failure and never retried: the content is finished the moment it is recognised, and
     * nothing in the archive or on the file store is touched.
     */
    case Skipped = 'skipped';
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
            self::Missing => 'the source PDF is not on the file store',
            self::Skipped => 'the profile is not converted',
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
