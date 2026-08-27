<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The photographer's explicit creative-authority decision on a proposal.
        Schema::create('photographer_decisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photographer_id')->constrained('users');
            $table->string('decision', 24); // approve | reject | modify
            $table->text('note')->nullable();
            $table->json('modifications')->nullable(); // for modify decisions
            $table->timestamps();

            $table->index(['project_id', 'proposal_id']);
            $table->index(['project_id', 'photographer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('photographer_decisions');
    }
};
