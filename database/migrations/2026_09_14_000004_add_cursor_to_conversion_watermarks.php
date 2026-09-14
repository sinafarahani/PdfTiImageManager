<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a walk of the archive's own tables got to.
 *
 * The existing watermark is a ProcessDate, because discovery scans GeneralContent by time. The two
 * commands that walk MVDContent cannot: it has no useful timestamp to order by, and its ID is the
 * clustered key, so they page by ID instead and need somewhere to leave one.
 *
 * It matters because these are runs of hours over 140 million rows. Without a cursor an interrupted
 * run starts again from the beginning, and re-examines every row it has already cleared.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversion_watermarks', function (Blueprint $table): void {
            $table->string('cursor', 64)->nullable()->after('processed_until');
        });
    }

    public function down(): void
    {
        Schema::table('conversion_watermarks', function (Blueprint $table): void {
            $table->dropColumn('cursor');
        });
    }
};
