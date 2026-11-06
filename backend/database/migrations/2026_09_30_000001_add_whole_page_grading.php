<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 parts C + E and §21.8 (Phase 8 build step 2, whole-page grading):
 *
 * - submission_pages: files of a whole-page submission (Classroom attachment,
 *   later also the student's or the teacher's upload). `result` (not in the
 *   §19.8 listing, added to DESIGN with this migration) holds the page's
 *   per-question extraction until every page of the submission is done and
 *   the results are merged;
 * - submissions: channel, submitted_at, late, regrade_pending;
 * - responses: scan_id nullable (whole-page answers have no scan),
 *   submission_page_id, ai_explanation (Gemini's text once the teacher edits
 *   it), explanation_source;
 * - classroom_submission_imports: every state of §19.8 and `late`;
 * - ai_calls: the purposes and token columns of §21.8.
 */
return new class extends Migration
{
    private const IMPORT_STATES = ['new', 'imported', 'needs_retake', 'returned_for_retake', 'graded', 'grade_failed'];

    private const IMPORT_STATES_NEW = [...self::IMPORT_STATES, 'waiting_key', 'rejected_late', 'unsupported'];

    private const PURPOSES = ['extract', 'rubric_draft', 'explanation', 'practice_gen'];

    private const PURPOSES_NEW = [
        'extract', 'extract_batch', 'extract_page', 'rubric_draft', 'explanation', 'practice_gen',
        'answer_key_read', 'answer_key_draft', 'document_read', 'indicator_suggest', 'student_analysis',
    ];

    public function up(): void
    {
        Schema::create('submission_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['classroom', 'student_app', 'teacher_upload']);
            $table->string('google_submission_id', 64)->nullable();
            $table->string('drive_file_id', 128)->nullable();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('mime_type', 100);
            $table->unsignedTinyInteger('page_count')->default(1);
            $table->unsignedInteger('size_bytes');
            $table->char('sha256', 64);
            $table->string('file_path')->nullable();
            $table->enum('state', ['stored', 'grading', 'graded', 'failed', 'superseded'])->default('stored');
            $table->json('result')->nullable();
            $table->timestamp('received_at')->useCurrent();
            $table->timestamps();
            $table->index(['submission_id', 'state'], 'idx_pages_submission');
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->enum('channel', ['scan', 'whole_page'])->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->boolean('late')->default(false);
            $table->boolean('regrade_pending')->default(false);
        });

        Schema::table('responses', function (Blueprint $table) {
            $table->foreignId('scan_id')->nullable()->change();
        });
        Schema::table('responses', function (Blueprint $table) {
            $table->foreignId('submission_page_id')->nullable()->constrained('submission_pages')->nullOnDelete();
            $table->text('ai_explanation')->nullable();
            $table->enum('explanation_source', ['ai', 'template', 'reused', 'teacher'])->nullable();
        });

        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->enum('state', self::IMPORT_STATES_NEW)->default('new')->change();
        });
        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->boolean('late')->default(false);
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', self::PURPOSES_NEW)->change();
        });
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->string('feature', 40)->nullable();
            $table->unsignedInteger('cached_tokens')->nullable();
            $table->unsignedInteger('thinking_tokens')->nullable();
            $table->enum('media_resolution', ['low', 'medium', 'high', 'ultra_high', 'mixed'])->nullable();
            $table->unsignedTinyInteger('image_count')->nullable();
            $table->unsignedTinyInteger('question_count')->nullable();
            $table->foreignId('assignment_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('batch')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assignment_id');
            $table->dropColumn(['feature', 'cached_tokens', 'thinking_tokens', 'media_resolution', 'image_count', 'question_count', 'batch']);
        });
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', self::PURPOSES)->change();
        });

        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->dropColumn('late');
        });
        Schema::table('classroom_submission_imports', function (Blueprint $table) {
            $table->enum('state', self::IMPORT_STATES)->default('new')->change();
        });

        Schema::table('responses', function (Blueprint $table) {
            $table->dropConstrainedForeignId('submission_page_id');
            $table->dropColumn(['ai_explanation', 'explanation_source']);
        });
        Schema::table('responses', function (Blueprint $table) {
            $table->foreignId('scan_id')->nullable(false)->change();
        });

        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['channel', 'submitted_at', 'late', 'regrade_pending']);
        });

        Schema::dropIfExists('submission_pages');
    }
};
