<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which source PDF rows a conversion actually hid.
 *
 * Without this there is no way to tell the two kinds of hidden PDF apart. A content's MVDContent can
 * hold several PDF rows flagged Deleted = 1: the one this pipeline converted and then hid, and any an
 * archive user deleted in the viewer years ago - an old revision, a mistake - which was never
 * rendered, has no pages anywhere, and is fully recoverable by unsetting one flag.
 *
 * hiddenSourcesFor() returns both, so a purge driven off it destroys documents the panel never
 * converted. This table is the difference: the mvd ids the conversion hid itself, written at the
 * moment it hid them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversion_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversion_id')->constrained()->cascadeOnDelete();
            $table->uuid('content_id');
            $table->uuid('mvd_id');
            $table->timestamps();

            $table->unique(['conversion_id', 'mvd_id']);
            $table->index('content_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_sources');
    }
};
