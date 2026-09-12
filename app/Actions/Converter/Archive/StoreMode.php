<?php

namespace App\Actions\Converter\Archive;

/**
 * Where a profile keeps its page images. Every large profile in the archive uses "fs" (the image is
 * uploaded to FTP and only the thumbnail is stored in the database); "db" exists but is used by a
 * handful of contents. Anything unknown is treated as "db", which is what the archive's own
 * procedure did.
 */
enum StoreMode: string
{
    case FileSystem = 'fs';
    case Database = 'db';

    public static function fromArchive(?string $value): self
    {
        return self::tryFrom(strtolower(trim((string) $value))) ?? self::Database;
    }

    public function storesImageInDatabase(): bool
    {
        return $this === self::Database;
    }
}
