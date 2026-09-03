<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * B3 — executed-proposal revert support.
 *
 * - reverted_at: stamped when a photographer reverts this derivative's
 *   execution. Reverted rows keep their bytes (history) but stop feeding
 *   the retouch truth card / executed-values surfaces.
 * - prior_photo_state: the photo's retouch_state captured at execution
 *   time, so a revert restores exactly what was there before — not a guess.
 *   NULL for derivatives executed before this migration (revert falls back
 *   to Domain::RETOUCH_NONE for those legacy rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_derivatives', function (Blueprint $table) {
            $table->timestamp('reverted_at')->nullable()->after('provenance');
            $table->string('prior_photo_state', 32)->nullable()->after('reverted_at');
        });
    }

    public function down(): void
    {
        Schema::table('photo_derivatives', function (Blueprint $table) {
            $table->dropColumn(['reverted_at', 'prior_photo_state']);
        });
    }
};
