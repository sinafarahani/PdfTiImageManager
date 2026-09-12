<?php

namespace App\Actions\Converter\Ftp;

use RuntimeException;

/**
 * An FTP operation failed. $transient marks the failures worth retrying (timeouts, dropped
 * connections, "try again later" replies) as opposed to a missing file or a rejected login.
 */
class FileStoreException extends RuntimeException
{
    public function __construct(string $message, public readonly bool $transient = false)
    {
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
}
