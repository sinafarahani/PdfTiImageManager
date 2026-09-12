<?php

namespace App\Actions\Converter\Ftp;

/**
 * The file store on a local disk. A development machine and the pipeline tests use it in place of
 * the archive's FTP site, so that the whole conversion can be run without one; $root plays the part
 * the site folder plays there, and paths below it have the same shape ("2025/01/07/16/39/53/<id>").
 */
class LocalFileStore implements FileStore
{
    public function __construct(private readonly string $root) {}

    /**
     * @return list<string>
     */
    public function list(string $folder): array
    {
        $path = $this->path($folder);

        if (! is_dir($path)) {
            throw FileStoreException::permanent("The folder {$path} is not there");
        }

        $names = array_values(array_diff((array) @scandir($path), ['.', '..']));
        sort($names);

        return $names;
    }

    public function download(string $remotePath, string $localPath): int
    {
        $path = $this->path($remotePath);

        if (! is_file($path)) {
            // Absent, not merely permanent: the pipeline gives a content whose source file is gone up
            // at once, and the two stores have to agree about that or the tests prove nothing.
            throw FileStoreException::absent("The file {$path} is not there");
        }

        if (! is_dir(dirname($localPath))) {
            // The FTP client calls this permanent too: a workspace folder that is not there is not
            // something a second attempt creates, and retrying it only spends the content's
            // attempts.
            throw FileStoreException::permanent("The folder {$localPath} cannot be written to");
        }

        // The copy lands beside the file and is renamed once it is whole, so that a failure never
        // leaves a half PDF behind - the same promise the FTP client makes.
        $partial = $localPath.'.part';

        if (! @copy($path, $partial)) {
            @unlink($partial);

            throw FileStoreException::transient("The file {$path} cannot be copied to {$localPath}");
        }

        clearstatcache(true, $partial);
        $bytes = (int) filesize($partial);
        @unlink($localPath);

        if (! @rename($partial, $localPath)) {
            @unlink($partial);

            throw FileStoreException::permanent("The file {$path} cannot be moved to {$localPath}");
        }

        return $bytes;
    }

    public function upload(string $localPath, string $remotePath): int
    {
        clearstatcache(true, $localPath);
        $size = @filesize($localPath);
        $path = $this->path($remotePath);

        if ($size === false) {
            throw FileStoreException::permanent("The file {$localPath} cannot be read for {$path}");
        }

        $folder = dirname($path);

        if (! is_dir($folder) && ! @mkdir($folder, recursive: true) && ! is_dir($folder)) {
            throw FileStoreException::transient("The folder {$folder} cannot be created");
        }

        $partial = $path.'.part';

        if (! @copy($localPath, $partial) || ! @rename($partial, $path)) {
            @unlink($partial);

            throw FileStoreException::transient("The file {$localPath} cannot be written to {$path}");
        }

        clearstatcache(true, $path);
        $stored = (int) filesize($path);

        if ($stored !== $size) {
            throw FileStoreException::transient("The file {$path} holds {$stored} of {$size} bytes");
        }

        return $stored;
    }

    public function size(string $remotePath): ?int
    {
        $path = $this->path($remotePath);
        clearstatcache(true, $path);

        return is_file($path) ? (int) filesize($path) : null;
    }

    public function delete(string $remotePath): void
    {
        $path = $this->path($remotePath);

        if (is_file($path) && ! @unlink($path)) {
            throw FileStoreException::transient("The file {$path} cannot be deleted");
        }
    }

    public function disconnect(): void
    {
        // There is no session to close.
    }

    /**
     * $path below the root, with the separators collapsed so that "2025/01//07/x.jpg" is one path.
     */
    private function path(string $path): string
    {
        $segments = [];

        foreach (preg_split('~[\\\\/]+~', $path) ?: [] as $segment) {
            $segment = trim($segment);

            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                throw FileStoreException::permanent("The path {$path} leaves the store");
            }

            $segments[] = $segment;
        }

        $root = rtrim($this->root, '\\/');

        return $segments === [] ? $root : $root.DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, $segments);
    }
}
