<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4 `classroom_submission_imports`: one row per Google Classroom
 * studentSubmission of a posted assignment, synced by
 * GET /assignments/{id}/google-submissions (TURNED_IN with attachments) or
 * created by PushClassroomGradeJob for a student who handed in on paper.
 *
 * state: new (photos to scan) -> imported (POST /scans accepted one of them)
 * -> graded (the published total reached Classroom); needs_retake /
 * returned_for_retake (sent back for a new photo); grade_failed (the grade
 * push gave up, last_error says why).
 *
 * alternate_link (not in DESIGN §18.4): the submission's own link in the
 * Classroom web app, which §18.6 returns per row and §18.2 offers the teacher
 * for typing a private comment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_submission_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->string('google_submission_id', 64)->unique();
            $table->string('google_user_id', 64);
            $table->foreignId('student_id')->nullable()->constrained('users')->nullOnDelete();
            $table->enum('state', ['new', 'imported', 'needs_retake', 'returned_for_retake', 'graded', 'grade_failed'])->default('new');
            $table->json('attachments');
            $table->string('google_update_time', 40);
            $table->string('alternate_link', 512)->nullable();
            $table->string('retake_reason')->nullable();
            $table->timestamp('grade_pushed_at')->nullable();
            $table->string('last_error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('updated_at')->useCurrent();
            $table->index(['assignment_id', 'state'], 'idx_imports_assignment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_submission_imports');
    }
};
