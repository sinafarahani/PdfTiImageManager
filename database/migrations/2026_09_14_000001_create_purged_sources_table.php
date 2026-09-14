<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What converters:purge-sources destroyed, and where it used to be.
 *
 * Everything else this pipeline does can be undone. This cannot: the source PDF is the only copy of
 * the original document, and the MVDContent row is the only record of its name and of the
 * CreateDateTime that says which folder its file was in. Delete the row and the file and there is
 * nothing left anywhere that says either of them ever existed.
 *
 * So the details are copied here first and kept afterwards. It is not a way back - nothing brings the
 * file back - but it is the difference between "some originals were deleted" and being able to say
 * exactly which document, under which name, from which folder, and when.
 *
 * Deliberately no foreign key to conversions: the reconciler prunes orphaned conversion rows, and an
 * audit record must outlive the thing it is about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purged_sources', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_id');
            $table->uuid('mvd_id');
            $table->unsignedBigInteger('conversion_id')->nullable();

            // Where the file was, and what the archive called it.
            $table->string('remote_path', 400);
            $table->string('original_name', 400)->nullable();
            $table->string('create_date_time', 30)->nullable();

            // How far the purge got. Both are written before anything is destroyed, so a row with
            // neither set is a purge that was interrupted between recording and deleting.
            $table->boolean('file_deleted')->default(false);
            $table->boolean('row_deleted')->default(false);
            $table->unsignedInteger('bytes')->nullable();

            $table->timestamps();

            $table->unique('mvd_id');
            $table->index('content_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purged_sources');
    }
};
