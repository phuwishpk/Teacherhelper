<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §20.2 / §20.6 (Phase 9 build step 8): the level of a skill in the
 * core curriculum (strand → standard → indicator → sub_indicator), where it
 * came from (the curriculum CSV, a school admin's CSV, or a teacher who added
 * a missing indicator) and who added it (source = teacher).
 *
 * Existing rows: no parent → indicator, a parent → sub_indicator; rows of a
 * school (school_id set) came from a school admin's import. Uniqueness of
 * (school_id, code) stays in code (§8.2): MariaDB does not enforce UNIQUE
 * over the NULL school_id of curriculum rows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->enum('level', ['strand', 'standard', 'indicator', 'sub_indicator'])->default('indicator');
            $table->enum('source', ['curriculum', 'school_admin', 'teacher'])->default('curriculum');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        DB::table('skills')->whereNotNull('parent_id')->update(['level' => 'sub_indicator']);
        DB::table('skills')->whereNotNull('school_id')->update(['source' => 'school_admin']);
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['level', 'source']);
        });
    }
};
