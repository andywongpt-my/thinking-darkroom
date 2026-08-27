<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_findings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('severity', 16)->default('info'); // info | warning | error | critical
            $table->string('category', 48)->nullable(); // e.g. overexposure | consistency | metadata | duplicates
            $table->text('message');
            $table->json('details')->nullable();
            $table->string('status', 24)->default('open'); // open | acknowledged | resolved
            $table->timestamps();

            $table->index(['project_id', 'status']);
            $table->index(['photo_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_findings');
    }
};
