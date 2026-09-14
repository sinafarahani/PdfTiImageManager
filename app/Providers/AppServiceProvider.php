<?php

namespace App\Providers;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SqlServerArchive;
use App\Actions\Converter\Ftp\ArchiveFtpClient;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Ftp\LocalFileStore;
use App\Actions\Converter\Render\ImagickThumbnailer;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\Pdf2ImgRenderer;
use App\Actions\Converter\Render\Thumbnailer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The archive database. The write mode is passed explicitly because SqlServerArchive refuses
        // to write unless it is told "on": a forgotten binding is a dry run, never a live write.
        $this->app->singleton(ArchiveGateway::class, fn (): ArchiveGateway => new SqlServerArchive(
            DB::connection((string) config('converter.archive.connection')),
            (string) config('converter.archive.write_mode'),
        ));

        // Resolved only when a conversion actually needs it, because asking the archive which FTP
        // site is current is a query - and both drivers need the answer. The site's own folder is
        // what the FTP client prefixes to every path, so the disk driver prefixes the same thing
        // under the configured root: one archive setting, two ways of reaching the same files.
        $this->app->singleton(FileStore::class, function (Application $app): FileStore {
            $site = $app->make(ArchiveGateway::class)->currentFileSite();

            $driver = strtolower(trim((string) config('converter.store.driver')));

            if ($driver === 'ftp' || $driver === '') {
                return new ArchiveFtpClient($site, (array) config('converter.ftp'));
            }

            // Never fall through to FTP on a value that is not understood. A deployment that meant to
            // read the disk and quietly went back to FTP would work, slowly, and nobody would know.
            if ($driver !== 'disk') {
                throw new RuntimeException("CONVERTER_STORE is \"{$driver}\"; it must be \"ftp\" or \"disk\".");
            }

            $root = trim((string) config('converter.store.root'));

            if ($root === '') {
                throw new RuntimeException('CONVERTER_STORE is "disk" but CONVERTER_STORE_ROOT is not set.');
            }

            // The site's folder comes from the archive, so both drivers derive the path identically.
            $path = rtrim($root, '\\/').DIRECTORY_SEPARATOR.trim($site->folder);

            // Checked once, here, rather than one content at a time. A root that is not there makes
            // every source look missing, and a missing source is the one verdict the pipeline never
            // retries - an unmounted volume would mark every content it reached beyond help.
            if (! is_dir($path)) {
                throw new RuntimeException("CONVERTER_STORE is \"disk\" but {$path} is not a folder on this machine.");
            }

            return new LocalFileStore($path);
        });

        $this->app->bind(PageRenderer::class, Pdf2ImgRenderer::class);
        $this->app->bind(Thumbnailer::class, ImagickThumbnailer::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
