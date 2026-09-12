<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The index the dashboard and the dispatcher's circuit breaker need.
 *
 * conversions holds one row per content, so on this archive it is a table of well over half a
 * million rows within the first day. Three of the panel's queries ask for rows of one status in
 * finished_at order - "converted today", the failure list under it, and the recent machine failures
 * the dispatcher checks before handing out work - and the original indexes ([status, id] and
 * finished_at on its own) serve none of them: MySQL picks one column and filters the rest, which for
 * "converted today" means reading every done row, and for the failure list means sorting every
 * failed row on every poll. The dashboard polls every two seconds.
 *
 * status first, because all three queries pin it to one value; finished_at second, because they then
 * want a range of it or an order by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->index(['status', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'finished_at']);
        });
    }
};
