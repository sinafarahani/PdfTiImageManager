<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The converter's own bookkeeping, in the panel's database. Nothing of ours is created in the
 * archive: these tables are the work queue, the record of every page written (so an interrupted
 * conversion can be cleaned up exactly instead of guessed at), and the discovery watermark.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('content_id')->unique();
            $table->unsignedInteger('profile_id')->nullable();

            // pending -> claimed -> done | failed | cancelled
            $table->string('status', 20)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);

            // Which pipeline step a failure happened in, and why, so the panel can group failures.
            $table->string('failure_stage', 30)->nullable();
            $table->text('failure_reason')->nullable();

            $table->unsignedSmallInteger('pages')->nullable();
            $table->string('worker', 100)->nullable();
            $table->timestamp('claimed_at')->nullable();

            // A conversion whose worker stopped updating this is picked up again by the reconciler.
            $table->timestamp('heartbeat_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            // The claim: the oldest pending rows first.
            $table->index(['status', 'id']);
            $table->index('heartbeat_at');
            $table->index('finished_at');
        });

        Schema::create('conversion_pages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversion_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seq');

            // The MVDContent row this page created, and where its image was uploaded. Both are
            // recorded before the work is done, so a cleanup knows exactly what to remove.
            $table->uuid('mvd_id')->nullable();
            $table->string('remote_path', 400)->nullable();
            $table->unsignedInteger('bytes')->nullable();
            $table->boolean('uploaded')->default(false);
            $table->timestamps();

            $table->unique(['conversion_id', 'seq']);
            $table->index('mvd_id');
        });

        Schema::create('conversion_watermarks', function (Blueprint $table): void {
            $table->string('name', 50)->primary();
            $table->timestamp('processed_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_pages');
        Schema::dropIfExists('conversions');
        Schema::dropIfExists('conversion_watermarks');
    }
};
