<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users'); // the agent user (or photographer)
            $table->string('type', 32); // cull | retouch | batch_retouch | qa_resolution
            $table->string('status', 24)->default('draft'); // draft | pending_review | approved | modified | rejected | executed
            $table->text('summary')->nullable();
            $table->json('payload')->nullable(); // free-form proposal reasoning / params
            $table->foreignId('supersedes_id')->nullable()->constrained('proposals')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['project_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
