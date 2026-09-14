<?php

namespace App\Actions\Converter\Archive;

/**
 * One MVDContent row of a content: either a source file (a PDF, whose PageNo holds the original file
 * name) or a page this pipeline produced (whose PageNo is the page number).
 */
final readonly class SourceFile
{
    public function __construct(
        public string $mvdId,
        public ?int $seqPageNo,
        public string $pageNo,
        public string $createDateTime,
        public string $format,
        public ?int $ftpSiteId,

        /**
         * The content this row belongs to, when it was read without knowing it already. The queries
         * that ask for one content's rows leave it null - the caller has it in hand - and the ones
         * that page through the archive fill it in, because a row found that way is the only thing
         * that says which document it is part of.
         */
        public ?string $contentId = null,
    ) {}

    public function isPdf(): bool
    {
        return str_contains(strtolower($this->format), 'pdf');
    }

    public function isImage(): bool
    {
        return str_contains(strtolower($this->format), 'image');
    }

    /**
     * The name the file has on FTP: the archive stores every file under its own MVDContent ID.
     */
    public function remoteFileName(): string
    {
        $extension = str_contains($this->format, '/') ? explode('/', $this->format)[1] : 'pdf';

        return $this->mvdId.'.'.strtolower(trim($extension));
    }

    /**
     * The folder the file lives in, derived from its own CreateDateTime ("2025-01-07 16:39:53"
     * becomes "2025/01/07/16/39/53"). The stored timestamp and the folder must always agree, which
     * is why both come from this one value.
     */
    public function remoteFolder(): string
    {
        return self::folderFor($this->createDateTime);
    }

    public static function folderFor(string $createDateTime): string
    {
        [$date, $time] = array_pad(explode(' ', trim($createDateTime), 2), 2, '');

        return implode('/', array_merge(explode('-', $date), explode(':', $time)));
    }
}
