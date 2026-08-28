<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 — non-destructive retouch derivatives.
 *
 * Original photos are IMMUTABLE. Every retouch execution produces a new
 * derivative JPEG recorded here; the source file is never overwritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_derivatives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 32); // retouch_preview | approved_render
            $table->string('storage_path'); // derivative location (public disk), never the original path
            $table->json('adjustments'); // normalized adjustment set used to render
            $table->string('provenance'); // honest renderer attribution
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['photo_id', 'type']); // repeated execution never duplicates
            $table->index(['project_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photo_derivatives');
    }
};
