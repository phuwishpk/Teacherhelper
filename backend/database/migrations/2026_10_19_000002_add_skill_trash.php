<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §34.1: a teacher's own indicator goes to the trash
 * (skills.deleted_at) before it can be deleted for good.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->index('idx_skills_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('skills', function (Blueprint $table) {
            $table->dropIndex('idx_skills_deleted');
            $table->dropColumn('deleted_at');
        });
    }
};
