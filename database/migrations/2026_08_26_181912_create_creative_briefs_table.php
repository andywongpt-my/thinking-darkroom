<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('creative_briefs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('client')->nullable();
            $table->date('shoot_date')->nullable();
            $table->string('location')->nullable();
            $table->text('creative_direction')->nullable();
            $table->text('tonality_notes')->nullable();
            $table->text('deliverables')->nullable();
            $table->string('status', 24)->default('active'); // draft | active | superseded
            $table->timestamps();

            $table->index('project_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_briefs');
    }
};
