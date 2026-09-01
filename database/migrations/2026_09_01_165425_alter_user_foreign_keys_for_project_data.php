<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('owner_id')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
        });

        $this->makeNullableUserForeignKey('proposals', 'created_by');
        $this->makeNullableUserForeignKey('photographer_decisions', 'photographer_id');
        $this->makeNullableUserForeignKey('brainstorm_sessions', 'photographer_id');
        $this->makeNullableUserForeignKey('creative_memories', 'photographer_id');
        $this->makeNullableUserForeignKey('creative_concepts', 'created_by');

        Schema::table('creative_memories', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('creative_memories', function (Blueprint $table) {
            $table->foreign('created_by')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign(['owner_id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('owner_id')
                ->references('id')
                ->on('users');
        });

        $this->restoreRequiredUserForeignKey('proposals', 'created_by');
        $this->restoreRequiredUserForeignKey('photographer_decisions', 'photographer_id');
        $this->restoreRequiredUserForeignKey('brainstorm_sessions', 'photographer_id');
        $this->restoreRequiredUserForeignKey('creative_memories', 'photographer_id');
        $this->restoreRequiredUserForeignKey('creative_concepts', 'created_by');

        Schema::table('creative_memories', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
        });

        Schema::table('creative_memories', function (Blueprint $table) {
            $table->foreign('created_by')
                ->references('id')
                ->on('users');
        });
    }

    private function makeNullableUserForeignKey(string $tableName, string $columnName): void
    {
        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->dropForeign([$columnName]);
        });

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->foreignId($columnName)->nullable()->change();
        });

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->foreign($columnName)
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });
    }

    private function restoreRequiredUserForeignKey(string $tableName, string $columnName): void
    {
        // NOTE (AGY 2026-09-02 audit Finding 2): rolling back fails with MySQL
        // error 1138 if any rows hold NULL user IDs from nullOnDelete() while
        // this migration was active. Backfill or purge those rows first.
        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->dropForeign([$columnName]);
        });

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->foreignId($columnName)->nullable(false)->change();
        });

        Schema::table($tableName, function (Blueprint $table) use ($columnName) {
            $table->foreign($columnName)
                ->references('id')
                ->on('users');
        });
    }
};
