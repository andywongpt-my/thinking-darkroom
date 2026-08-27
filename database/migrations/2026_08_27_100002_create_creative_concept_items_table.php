<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Individual readable traits/decisions inside a concept — the human
        // or agent can attach discrete notes per dimension (e.g. what this
        // concept means for selection, retouch, color) so a future culling
        // agent can consume them as structured intent.
        Schema::create('creative_concept_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('creative_concept_id')->constrained()->cascadeOnDelete();
            $table->string('dimension', 48); // mood | story | composition | lighting | color | subject_direction | selection_priorities | retouch_philosophy | avoid | note
            $table->string('label', 255)->nullable();
            $table->text('value')->nullable();
            $table->string('source', 16)->default('agent'); // agent | photographer
            $table->timestamps();

            $table->index(['creative_concept_id', 'dimension']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('creative_concept_items');
    }
};
