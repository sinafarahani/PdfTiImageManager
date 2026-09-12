<?php

namespace App\Actions\Converter\Pipeline;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * The folder a content is converted in: its downloaded PDF and its rendered pages.
 *
 * Every content gets its own folder and it is deleted as soon as the pages are stored. The previous
 * pipeline only ever cleaned up in a branch that could not run, so the staging drive filled until
 * conversions failed with "there is not enough space on the disk" - 206 contents were lost that way.
 */
class ContentWorkspace
{
    public function __construct(
        private readonly ?string $root = null,
        private readonly ?int $freeSpaceFloorGb = null,
    ) {}

    /**
     * An empty folder for the content. Anything an earlier attempt left is deleted first: a retry
     * that read the previous attempt's images would store them a second time.
     *
     * @throws WorkspaceUnavailable when the drive is too full to work on
     */
    public function open(string $contentId): string
    {
        $this->assertSpaceIsAvailable();

        $path = $this->pathFor($contentId);
        File::deleteDirectory($path);
        File::ensureDirectoryExists($path);

        return $path;
    }

    /**
     * Deletes the folder. Windows may still hold a handle from the renderer for a moment after it
     * exits, so this retries briefly; a folder that survives is logged rather than thrown, because
     * the content itself was converted and must not be failed over a leftover file.
     */
    public function close(string $contentId): void
    {
        $path = $this->pathFor($contentId);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (! File::isDirectory($path)) {
                return;
            }

            if (File::deleteDirectory($path)) {
                return;
            }

            Sleep::for(250)->milliseconds();
        }

        Log::warning("Could not delete the workspace {$path}; it will be removed by converters:sweep.");
    }

    /**
     * @throws WorkspaceUnavailable when the content id could not be a folder name under the root
     */
    public function pathFor(string $contentId): string
    {
        // open() deletes this folder and close() deletes it again, so the name has to be a name. A
        // content id is a GUID everywhere it comes from the archive - but converters:try takes one
        // from the command line, and "../.." there would point both deletions at a folder outside
        // the workspace. Nothing downstream can undo that, so it is refused here.
        if (preg_match('/^[A-Za-z0-9_-]{1,100}$/', $contentId) !== 1) {
            throw new WorkspaceUnavailable(
                "\"{$contentId}\" cannot be a workspace folder name; a content id is a GUID."
            );
        }

        return $this->root().DIRECTORY_SEPARATOR.strtolower($contentId);
    }

    public function root(): string
    {
        return rtrim($this->root ?? (string) config('converter.workspace.root'), '\\/');
    }

    public function freeSpaceGb(): ?float
    {
        $root = $this->root();
        File::ensureDirectoryExists($root);
        $free = @disk_free_space($root);

        return $free === false ? null : $free / 1024 ** 3;
    }

    /**
     * @throws WorkspaceUnavailable
     */
    private function assertSpaceIsAvailable(): void
    {
        $floor = $this->freeSpaceFloorGb ?? (int) config('converter.workspace.free_space_floor_gb');
        $free = $this->freeSpaceGb();

        // A drive whose free space cannot be read (a network path, for example) is not a reason to
        // stop converting; only a reading that is genuinely below the floor is.
        if ($free !== null && $free < $floor) {
            throw new WorkspaceUnavailable(sprintf(
                '%s has %.1f GB free, below the %d GB the converter keeps clear',
                $this->root(), $free, $floor,
            ));
        }
    }
}
