<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 part B (Phase 8 build step 4, the cron sync with Google
 * Classroom):
 *
 * - google_accounts.reconnect_notified_at: the FCM "ต้องเชื่อมบัญชี Google
 *   ใหม่" went out for the current drop (cleared by a new connect);
 * - assignments.subject_id nullable: a mirror of courseWork the teacher
 *   created on the Classroom website has no subject until the teacher picks
 *   one when approving its key;
 * - assignment_google_links.origin / materials / last_synced_at: courseWork
 *   the app posted (`app`) or mirrored from the website (`classroom_web`,
 *   grades cannot be set there), its Drive materials, and the last sync of
 *   its submissions (the cron takes the oldest first);
 * - submissions.total_override: the total taken from Classroom
 *   (accept_classroom); the effective total is COALESCE(total_override, total_score);
 * - classroom_submission_imports.pushed_grade / classroom_grade: the grade
 *   the app sent last and the assignedGrade seen at the last sync;
 * - grade_conflicts: "คะแนนไม่ตรงกัน" and how the teacher resolved each one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('google_accounts', function (Blueprint $table) {
            $table->timestamp('reconnect_notified_at')->nullable();
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->change();
        });

        Schema::table('assignment_google_links', function (Blueprint $table) {
            $table->enum('origin', ['app', 'classroom_web'])->default('app');
            $table->json('materials')->nullable();
            $table->timestamp('last_synced_at')->nullable();
            $table->index('course_work_id');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->decimal('total_override', 6, 2)->nullable();
        });

        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->decimal('pushed_grade', 6, 2)->nullable();
            $table->decimal('classroom_grade', 6, 2)->nullable();
        });

        Schema::create('grade_conflicts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('import_id')->constrained('classroom_submission_imports')->restrictOnDelete();
            $table->decimal('app_score', 6, 2)->nullable();
            $table->decimal('classroom_score', 6, 2)->nullable();
            $table->enum('status', ['open', 'pushed_app', 'accepted_classroom', 'dismissed'])->default('open');
            $table->string('reason')->nullable();
            $table->timestamp('detected_at')->useCurrent();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->index(['submission_id', 'status'], 'idx_conflicts');
            $table->index(['import_id', 'id'], 'idx_conflicts_import');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('grade_conflicts');

        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->dropColumn(['pushed_grade', 'classroom_grade']);
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn('total_override');
        });

        Schema::table('assignment_google_links', function (Blueprint $table) {
            $table->dropIndex(['course_work_id']);
            $table->dropColumn(['origin', 'materials', 'last_synced_at']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable(false)->change();
        });

        Schema::table('google_accounts', function (Blueprint $table) {
            $table->dropColumn('reconnect_notified_at');
        });
    }
};
