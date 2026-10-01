<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.13, §22.14 (Phase 10 build step 6): published exam answers
 * feed mastery as skill_observations with source = exam (α = 0.30, the
 * same as homework, §14.2).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_observations', function (Blueprint $table) {
            $table->enum('source', ['homework', 'practice', 'exam'])->change();
        });
    }

    public function down(): void
    {
        // Same α as homework: the rows stay and the mastery values do not change.
        DB::table('skill_observations')->where('source', 'exam')->update(['source' => 'homework']);
        Schema::table('skill_observations', function (Blueprint $table) {
            $table->enum('source', ['homework', 'practice'])->change();
        });
    }
};
