<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 4 — LEARN: explicit photographer creative memory.
 *
 * Every approve/reject/modify/override the photographer performs can be
 * persisted as a durable creative-memory lesson ("prefer muted tones",
 * "never crush shadows") that FUTURE agent proposals must consume.
 * Photographer-taught; agent-readable. Never written by the agent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photographer_id')->constrained('users');
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            // lesson | preference | override — all photographer-authored
            $table->string('kind', 24)->default('lesson');
            $table->text('lesson'); // the durable statement of creative intent
            $table->foreignId('created_by')->nullable()->constrained('users'); // author (photographer)
            $table->json('context')->nullable(); // decision context snapshot
            $table->timestamps();

            $table->index(['project_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_memories');
    }
};
