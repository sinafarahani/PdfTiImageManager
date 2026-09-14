<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stops the discovery watermark losing the fraction of a second it was written with.
 *
 * GeneralContent.ProcessDate is a SQL Server datetime, which keeps fractions - it ticks every 3.33
 * milliseconds. The watermark was stored as a whole-second timestamp, so a content processed at
 * 08:15:37.123 was recorded as 08:15:37, and the next pass asked for ProcessDate > '08:15:37' and got
 * that same content straight back. Its ProcessDate truncated to 08:15:37 again, which is not later
 * than the mark, so the watermark could not move - and the pass repeated for ever.
 *
 * It wedges the scan permanently at the first content with a fractional second, which is most of
 * them. On the production archive discovery stopped dead at 2026-09-12 08:15:37 and stayed there,
 * finding the same single content every quarter of an hour and queueing nothing.
 *
 * The column takes microseconds, but the values written into it carry three digits, because that is
 * datetime's own resolution and the most SQL Server will accept in a string compared against one: a
 * literal with six digits is refused outright with "Conversion failed when converting date and/or
 * time from character string". The extra room costs nothing and is there if the archive ever grows a
 * datetime2 column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_watermarks', function (Blueprint $table): void {
            $table->timestamp('processed_until', 6)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('conversion_watermarks', function (Blueprint $table): void {
            $table->timestamp('processed_until')->nullable()->change();
        });
    }
};
