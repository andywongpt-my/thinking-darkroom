<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agent_conversation_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('author_kind', 16);
            $table->text('body');
            $table->uuid('client_message_id')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'id']);
            $table->unique(
                ['project_id', 'user_id', 'client_message_id'],
                'agent_conversation_idempotency',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_messages');
    }
};
