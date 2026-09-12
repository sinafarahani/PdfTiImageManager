<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a whole profile be taken out of the queue without reading the table.
 *
 * converters:skip asks for the waiting and failed contents of one profile, and it runs on the
 * schedule; without this it is a scan of half a million rows every pass. The profile comes first
 * because that is the selective half - a handful of profiles, two statuses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('conversions', function (Blueprint $table): void {
            $table->dropIndex(['profile_id', 'status']);
        });
    }
};
