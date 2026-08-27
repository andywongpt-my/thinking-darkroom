<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A freeform creative-thinking session. The photographer's brainstorm
        // input is the source context the agent reasons from. Kept deliberately
        // light — the creative decisions live in creative_concepts.
        Schema::create('brainstorm_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photographer_id')->constrained('users');
            $table->text('input'); // freeform photographer thinking
            $table->string('status', 24)->default('open'); // open | adopted | closed
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brainstorm_sessions');
    }
};
