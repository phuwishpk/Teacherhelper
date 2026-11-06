<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.5 (attendance per course and period):
 *
 * - attendance_sessions: one checked period of a course in a classroom.
 *   period_no NULL = "no period number" (one such session a day, checked in
 *   code: a unique key does not compare NULLs); starts_at / ends_at are for
 *   the timetable of step 2ข;
 * - attendance_records: one status per (session, student);
 * - courses.attendance_scores: the value of มาตรง / มาสาย / ขาด, each 0..1
 *   (NULL = 1, 0.5, 0); leaves are never counted;
 * - gradebook_items.auto_attendance: the "การเข้าเรียน" item whose scores the
 *   system writes from the records (one per course and classroom).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->date('held_on');
            $table->unsignedTinyInteger('period_no')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['course_id', 'classroom_id', 'held_on', 'period_no'], 'uq_attendance_period');
        });

        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attendance_session_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users');
            $table->enum('status', ['present', 'late', 'absent', 'personal_leave', 'sick_leave']);
            $table->string('note', 255)->nullable();
            $table->foreignId('updated_by')->constrained('users');
            $table->timestamps();
            $table->unique(['attendance_session_id', 'student_id'], 'uq_attendance_record');
            $table->index('student_id', 'idx_attendance_student');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->json('attendance_scores')->nullable();
        });

        Schema::table('gradebook_items', function (Blueprint $table) {
            $table->boolean('auto_attendance')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('gradebook_items', function (Blueprint $table) {
            $table->dropColumn('auto_attendance');
        });
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('attendance_scores');
        });
        Schema::dropIfExists('attendance_records');
        Schema::dropIfExists('attendance_sessions');
    }
};
