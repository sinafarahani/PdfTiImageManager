<?php

namespace App\Actions\Converter\Ftp;

/**
 * The archive's file store: the FTP server that holds the source PDFs and the page images. Paths are
 * relative to the site's own folder (for example "2025/01/07/16/39/53/<id>.jpg"), which the
 * implementation prefixes.
 *
 * Every method has a timeout and gives up with a FileStoreException rather than blocking: the
 * previous pipeline set no timeouts at all and lost 4382 contents to hung listings.
 */
interface FileStore
{
    /**
     * File names (without folders) in $folder. An empty array means the folder is empty; a folder
     * that does not exist is a FileStoreException.
     *
     * @return list<string>
     */
    public function list(string $folder): array;

    /**
     * Downloads $remotePath to $localPath and returns the number of bytes written. A partial
     * download never leaves a file behind at $localPath.
     */
    public function download(string $remotePath, string $localPath): int;

    /**
     * Uploads $localPath to $remotePath, creating the folders it needs, and returns the size the
     * server reports afterwards, so the caller can verify the upload arrived intact.
     */
    public function upload(string $localPath, string $remotePath): int;

    /**
     * The size the server reports, or null when the file is not there.
     */
    public function size(string $remotePath): ?int;

    /**
     * Removes a file. A file that is already gone is not an error.
     */
    public function delete(string $remotePath): void;

    /**
     * Closes the session. The pipeline opens one session per content and closes it before the
     * long, CPU-bound rendering step, so that idle sessions do not sit on the server's connection
     * limit.
     */
    public function disconnect(): void;
}
