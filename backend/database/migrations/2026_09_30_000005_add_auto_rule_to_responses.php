<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 / §21.3 (Phase 8 build step 7): responses.auto_rule, set when
 * the answer was decided by code without calling Gemini: `blank_ink` (an
 * empty answer box, 0 points) or `cnn_match` (the on-device digit reader is
 * sure and reads an accepted answer, full marks). NULL = graded through
 * Gemini as before. The savings report counts these rows (§21.8).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->enum('auto_rule', ['blank_ink', 'cnn_match'])->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->dropColumn('auto_rule');
        });
    }
};
