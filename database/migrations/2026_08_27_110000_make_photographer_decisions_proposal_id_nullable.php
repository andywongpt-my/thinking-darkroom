<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Sprint 2: concept-level creative decisions (reject_concept /
     * adopt_concept) have no Proposal row. Make proposal_id nullable so the
     * photographer-decision audit trail can carry both proposal decisions and
     * Creative Room decisions.
     */
    public function up(): void
    {
        Schema::table('photographer_decisions', function (Blueprint $table) {
            $table->foreignId('proposal_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('photographer_decisions', function (Blueprint $table) {
            $table->foreignId('proposal_id')->nullable(false)->change();
        });
    }
};
