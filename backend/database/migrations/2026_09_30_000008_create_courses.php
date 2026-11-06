<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §20.1 / §20.6 (Phase 9 build step 8): a teacher's course
 * (รายวิชา, e.g. ค15101 คณิตศาสตร์ 5), created once and bound to many
 * classrooms, with its indicators, optional units (หน่วยการเรียนรู้) and
 * lesson plans (แผนการจัดการเรียนรู้), each with their own indicators.
 * Assignments point at a course and, optionally, a lesson plan.
 *
 * semester 0 = the whole year (not NULL: UNIQUE does not stop duplicate
 * NULLs in MariaDB); academic_year is the Buddhist year (พ.ศ.).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('subject_id')->constrained()->restrictOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->unsignedTinyInteger('grade_level');
            $table->unsignedTinyInteger('semester')->default(0);
            $table->unsignedSmallInteger('academic_year');
            $table->unsignedSmallInteger('hours')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'created_by', 'code', 'academic_year', 'semester'], 'uq_course');
        });

        Schema::create('course_classroom', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->primary(['course_id', 'classroom_id']);
        });

        Schema::create('course_indicators', function (Blueprint $table) {
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->primary(['course_id', 'skill_id']);
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->unsignedSmallInteger('hours')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->unique(['course_id', 'position'], 'uq_unit_position');
        });

        Schema::create('unit_indicators', function (Blueprint $table) {
            $table->foreignId('unit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->primary(['unit_id', 'skill_id']);
        });

        Schema::create('lesson_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->unsignedSmallInteger('hours')->nullable();
            $table->text('objectives')->nullable();
            $table->text('content')->nullable();
            $table->text('activities')->nullable();
            $table->text('assessment')->nullable();
            $table->date('taught_on')->nullable();
            $table->timestamps();
            $table->index(['course_id', 'position'], 'idx_plans_course');
        });

        Schema::create('lesson_plan_indicators', function (Blueprint $table) {
            $table->foreignId('lesson_plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->primary(['lesson_plan_id', 'skill_id']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('course_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('lesson_plan_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('lesson_plan_id');
            $table->dropConstrainedForeignId('course_id');
        });
        Schema::dropIfExists('lesson_plan_indicators');
        Schema::dropIfExists('lesson_plans');
        Schema::dropIfExists('unit_indicators');
        Schema::dropIfExists('units');
        Schema::dropIfExists('course_indicators');
        Schema::dropIfExists('course_classroom');
        Schema::dropIfExists('courses');
    }
};
