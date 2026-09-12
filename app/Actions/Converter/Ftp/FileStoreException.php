<?php

namespace App\Actions\Converter\Ftp;

use RuntimeException;

/**
 * An FTP operation failed. $transient marks the failures worth retrying (timeouts, dropped
 * connections, "try again later" replies) as opposed to a missing file or a rejected login.
 */
class FileStoreException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $transient = false,
        public readonly bool $absent = false,
    ) {
        parent::__construct($message);
    }

    public static function transient(string $message): self
    {
        return new self($message, transient: true);
    }

    public static function permanent(string $message): self
    {
        return new self($message);
    }

    /**
     * The file is not on the store at all.
     *
     * Its own kind, because it is the one failure that says something about the archive rather than
     * about the transfer: thousands of the archive's rows name files that are no longer on the site,
     * and a content like that is given up on immediately instead of being retried. Some servers refuse
     * such a request with no reply code and no recognisable wording at all - "failed: End" - so the
     * words are never what decides this.
     */
    public static function absent(string $message): self
    {
        return new self($message, absent: true);
    }
}
