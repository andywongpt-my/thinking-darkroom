<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P2c — per-photographer AI provider settings (BYO key). Keys are stored
 * ENCRYPTED (Laravel Crypt, APP_KEY) — never plaintext. Provider presets:
 * openrouter (default) and nvidia_nim (integrate.api.nvidia.com/v1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ai_provider')->nullable()->after('is_agent');
            $table->text('ai_api_key_encrypted')->nullable()->after('ai_provider');
            $table->string('ai_model')->nullable()->after('ai_api_key_encrypted');
            $table->string('ai_base_url')->nullable()->after('ai_model');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_key_encrypted', 'ai_model', 'ai_base_url']);
        });
    }
};
