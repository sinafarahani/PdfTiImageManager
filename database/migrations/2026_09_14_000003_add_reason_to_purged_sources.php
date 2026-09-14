<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Why a source row is not in the archive any more.
 *
 * Two commands remove them and they are not the same act. converters:purge-sources destroys a
 * converted source that was still there - the file and the row - and that is irreversible.
 * converters:prune-orphans removes a row whose file had already been deleted by hand months ago, and
 * destroys nothing: the document was lost when the file went, and what is left is a pointer to it.
 *
 * Anyone reading this table after an incident needs to be able to tell those apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purged_sources', function (Blueprint $table): void {
            $table->string('reason', 20)->default('purged')->after('mvd_id');
            $table->index('reason');
        });
    }

    public function down(): void
    {
        Schema::table('purged_sources', function (Blueprint $table): void {
            $table->dropIndex(['reason']);
            $table->dropColumn('reason');
        });
    }
};
