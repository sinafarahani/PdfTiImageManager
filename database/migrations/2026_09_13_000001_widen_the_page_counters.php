<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a content have more than 65,535 pages.
 *
 * Both counters were smallints, and the archive has at least one content that overruns them: on
 * 2026-09-13 conversion 267586 rendered and uploaded 65,535 pages over eleven hours and then threw
 * "Out of range value for column 'seq'" on the next one. It did that twice.
 *
 * The two have to be widened together, and seq alone would be worse than neither. seq is written per
 * page, before any of the archive work for that page, so it fails early and the rollback undoes an
 * attempt that had not yet been committed anywhere. pages is written by succeed(), three lines AFTER
 * markConverted() - so a content that got past a widened seq and overran pages instead would render
 * every page, write every archive row, upload every image, be marked converted in GeneralContent, and
 * only then throw, with the rollback deleting everything it had just written. A content the archive
 * believes is converted and that has no pages is precisely the failure this pipeline exists to make
 * impossible, and it is the one the old pipeline left behind 10,601 times.
 *
 * unsignedInteger, not bigInteger: four billion pages is past any document, and seq is half of a
 * unique index that every page insert checks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->unsignedInteger('pages')->nullable()->change();
        });

        Schema::table('conversion_pages', function (Blueprint $table): void {
            $table->unsignedInteger('seq')->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->unsignedSmallInteger('pages')->nullable()->change();
        });

        Schema::table('conversion_pages', function (Blueprint $table): void {
            $table->unsignedSmallInteger('seq')->change();
        });
    }
};
