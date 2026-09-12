<?php

namespace App\Actions\Converter\Archive;

/**
 * One page to write into the archive. $createDateTime is both what MVDContent stores and what the
 * page's FTP folder is derived from, so the row and the file can never point at different places.
 */
final readonly class PageInsert
{
    public function __construct(
        public string $contentId,
        public int $seqPageNo,
        public string $createDateTime,
        public string $format,
        public int $ftpSiteId,
        public string $thumbnail,
        public ?string $image = null,
    ) {}

    /**
     * MVDContent.PageNo of a converted page is the page number as text.
     */
    public function pageNo(): string
    {
        return (string) $this->seqPageNo;
    }
}
