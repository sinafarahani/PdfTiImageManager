<?php

namespace App\Actions\Converter\Archive;

use SensitiveParameter;

/**
 * The archive's current FTP site for files. The password is kept out of every string form of this
 * object so that it cannot reach a log or an exception message.
 */
final readonly class FtpSite
{
    public function __construct(
        public int $id,
        public string $host,
        public int $port,
        public string $folder,
        public string $username,
        #[SensitiveParameter]
        private string $password,
    ) {}

    public function password(): string
    {
        return $this->password;
    }

    public function __toString(): string
    {
        return "ftp://{$this->username}@{$this->host}:{$this->port}/{$this->folder}";
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => $this->port,
            'folder' => $this->folder,
            'username' => $this->username,
            'password' => '***',
        ];
    }
}
