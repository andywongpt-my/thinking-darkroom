<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Extensible structured payload for a Creative Brief: stores the full
        // derived (or proposed) brief dimensions as JSON, so we never create
        // rigid SQL columns per creative property. Existing rows keep their
        // legacy scalar columns; `payload` is the canonical structured form
        // for Sprint 2+ consumers (e.g. the Sprint 3 culling contract).
        Schema::table('creative_briefs', function (Blueprint $table) {
            $table->json('payload')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('creative_briefs', function (Blueprint $table) {
            $table->dropColumn('payload');
        });
    }
};
