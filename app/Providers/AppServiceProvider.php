<?php

namespace App\Providers;

use App\Actions\Converter\Archive\ArchiveGateway;
use App\Actions\Converter\Archive\SqlServerArchive;
use App\Actions\Converter\Ftp\ArchiveFtpClient;
use App\Actions\Converter\Ftp\FileStore;
use App\Actions\Converter\Render\ImagickThumbnailer;
use App\Actions\Converter\Render\PageRenderer;
use App\Actions\Converter\Render\Pdf2ImgRenderer;
use App\Actions\Converter\Render\Thumbnailer;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;

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
        // site is current is a query.
        $this->app->singleton(FileStore::class, fn (Application $app): FileStore => new ArchiveFtpClient(
            $app->make(ArchiveGateway::class)->currentFileSite(),
            (array) config('converter.ftp'),
        ));

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
