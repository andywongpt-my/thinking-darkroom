<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sprint 3 — context-aware culling persistence.
 *
 * One new table only: photo_observations. Everything else reuses the certified
 * Sprint 1/2 architecture (proposals, proposal_items, photographer_decisions,
 * agent_tool_calls, creative_briefs, photos).
 *
 * photo_observations rows are OBSERVED/ANALYZED data produced by a
 * PhotoAnalysisProvider — they are NEVER photographer decisions and never
 * change selection_state. Every row records its provider + provenance so the
 * demo can honestly state where analysis came from.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();

            // Structured observation payload (technical + creative sections).
            $table->json('payload');

            // Provenance: which provider produced this and how. Honest
            // attribution — e.g. demo_pixel_stats | fixture_synthetic | vlm.
            $table->string('provider', 48);
            $table->string('provenance', 48);

            // Lightweight duplicate/similarity grouping (Sprint 3: demo-grade).
            $table->string('similarity_group', 64)->nullable();
            $table->index(['project_id', 'photo_id']);
            $table->index('similarity_group');

            $table->timestamps();

            // One current observation per photo — re-analysis supersedes.
            $table->unique('photo_id');
        });

        // Photographer overrides on individual culling recommendations need a
        // photo reference; proposal_id stays nullable (Creative Room decisions
        // and photo-level overrides have no Proposal row).
        Schema::table('photographer_decisions', function (Blueprint $table) {
            $table->foreignId('photo_id')->nullable()->after('proposal_id')
                ->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('photographer_decisions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('photo_id');
        });
        Schema::dropIfExists('photo_observations');
    }
};
