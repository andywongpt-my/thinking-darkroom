<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposal_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('photo_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('kind', 32); // e.g. selection | retouch_operation | qa_check | cull
            $table->string('action', 64); // e.g. select | cull | set_white_balance | crop | ...
            $table->text('rationale')->nullable();
            $table->json('params')->nullable(); // validated, schema-bound parameters
            $table->json('result')->nullable(); // populated after execution
            $table->string('status', 24)->default('proposed'); // proposed | applied | skipped | failed
            $table->timestamps();

            $table->index('proposal_id');
            $table->index('photo_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('proposal_items');
    }
};
