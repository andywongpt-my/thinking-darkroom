<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Immutable audit trail of every agent tool call, including denied / unauthorized attempts.
        Schema::create('agent_tool_calls', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('agent_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('tool_name', 80); // e.g. inspect_photo
            $table->string('authority', 24); // READ | PROPOSE | EXECUTE
            $table->string('http_method', 8)->nullable();
            $table->string('path', 255)->nullable();
            $table->string('result_status', 24)->default('completed'); // completed | denied | warning | error
            $table->json('input')->nullable();
            $table->json('output_summary')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['project_id', 'created_at']);
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_tool_calls');
    }
};
