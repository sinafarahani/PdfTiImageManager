<?php

namespace App\Actions\Converter\Ftp;

/**
 * The file store, read for real and written nowhere: listing, downloading and asking for a size go
 * to the store this wraps, uploading and deleting are recorded only.
 *
 * The reads are deliberately not stubbed. A dry run whose download was faked would prove nothing at
 * all - the whole question it answers is whether the PDF the archive points at is really there, comes
 * down whole, and renders - so the PDF is fetched over the same FTP session the pipeline uses, and
 * only the two operations that would change the site are held back.
 */
class RecordingFileStore implements FileStore
{
    /**
     * @var list<array{remotePath: string, localPath: string, bytes: int}>
     */
    private array $downloads = [];

    /**
     * @var list<array{localPath: string, remotePath: string, bytes: int}>
     */
    private array $uploads = [];

    /**
     * @var list<string>
     */
    private array $deletions = [];

    public function __construct(private readonly FileStore $files) {}

    /**
     * What the run really fetched, with the bytes that arrived.
     *
     * @return list<array{remotePath: string, localPath: string, bytes: int}>
     */
    public function downloads(): array
    {
        return $this->downloads;
    }

    /**
     * What the run would have uploaded, and where to. $bytes is the size of the local image, which is
     * what a real upload would have put on the site.
     *
     * @return list<array{localPath: string, remotePath: string, bytes: int}>
     */
    public function uploads(): array
    {
        return $this->uploads;
    }

    /**
     * The remote paths the run would have deleted. The pipeline's rollback deletes the images of an
     * earlier attempt before it writes new ones, so this is not always empty even for a content that
     * converts cleanly.
     *
     * @return list<string>
     */
    public function deletions(): array
    {
        return $this->deletions;
    }

    /**
     * @return list<string>
     */
    public function list(string $folder): array
    {
        return $this->files->list($folder);
    }

    public function download(string $remotePath, string $localPath): int
    {
        $bytes = $this->files->download($remotePath, $localPath);

        $this->downloads[] = ['remotePath' => $remotePath, 'localPath' => $localPath, 'bytes' => $bytes];

        return $bytes;
    }

    public function upload(string $localPath, string $remotePath): int
    {
        clearstatcache(true, $localPath);
        $size = @filesize($localPath);

        // An image the renderer did not leave behind is a fault the report has to show, not swallow,
        // and it is not one a second attempt would fix - so it fails the way the real stores fail on
        // a file they cannot read.
        if ($size === false) {
            throw FileStoreException::permanent("The file {$localPath} cannot be read for {$remotePath}");
        }

        $this->uploads[] = ['localPath' => $localPath, 'remotePath' => $remotePath, 'bytes' => (int) $size];

        // The caller records this as the size that landed on the site, so the local size is the only
        // answer that keeps the run going and keeps the report truthful.
        return (int) $size;
    }

    public function size(string $remotePath): ?int
    {
        return $this->files->size($remotePath);
    }

    public function delete(string $remotePath): void
    {
        $this->deletions[] = $remotePath;
    }

    /**
     * Passed through: closing the session changes nothing on the site, and an FTP session left open
     * would sit on the server's connection limit - which is exactly what the pipeline closes it for
     * before it starts rendering.
     */
    public function disconnect(): void
    {
        $this->files->disconnect();
    }
}
