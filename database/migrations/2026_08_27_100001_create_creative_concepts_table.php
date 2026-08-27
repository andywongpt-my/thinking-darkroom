<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A structured creative concept proposed by the agent (or a
        // human-derived branch). The structured dimensions live in the JSON
        // `content` column (extensible — never rigid SQL columns per creative
        // property). Lineage is preserved via parent_concept_id and the
        // lineage_basis merge annotations.
        Schema::create('creative_concepts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('brainstorm_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('parent_concept_id')->nullable()->constrained('creative_concepts')->nullOnDelete();
            $table->string('title', 255);
            $table->text('summary')->nullable();
            // Extensible structured creative dimensions (mood, story,
            // composition, lighting, color, subject_direction,
            // selection_priorities, retouch_philosophy, avoid, …).
            $table->json('content')->nullable();
            // proposed | exploring | rejected | merged | adopted
            $table->string('status', 24)->default('proposed');
            $table->foreignId('created_by')->constrained('users');
            // lineage_basis: for merges — [ {concept_id, label, note} ]
            $table->json('lineage_basis')->nullable();
            $table->timestamp('adopted_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_concepts');
    }
};
