<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.2 `skills`: curriculum indicators (school_id NULL) and school-level
 * sub-skills (school_id set, parent_id -> the indicator). Uniqueness of
 * (school_id, code) is enforced by the importer, not the database, because
 * MariaDB treats NULLs in a UNIQUE key as distinct.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('skills')->restrictOnDelete();
            $table->foreignId('school_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('code', 40);
            $table->text('name');
            $table->unsignedTinyInteger('grade_level')->nullable();
            $table->timestamps();
            $table->index(['subject_id', 'grade_level'], 'idx_skills_subject_grade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skills');
    }
};
