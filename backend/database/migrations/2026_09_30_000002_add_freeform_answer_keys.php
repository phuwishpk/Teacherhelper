<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 parts B + D (Phase 8 build step 3, free-form assignments and
 * the teacher's answer key):
 *
 * - source_documents: files a teacher uploads (answer keys, question sheets),
 *   one row per upload, found again by (school_id, sha256);
 * - document_extractions: what Gemini read from them, read once and reused
 *   by every teacher of the school (UNIQUE school_id + input_hash + purpose);
 * - assignments: mode worksheet|freeform, source, accept_late, score_only,
 *   key_origin, key_approved_at / key_approved_by, and key_extraction_id
 *   (added to DESIGN with this migration: the extraction or AI draft the
 *   assignment's key waits for, which GET /answer-key reports);
 * - questions.model_answer: the teacher's model answer of an open question.
 *
 * Existing assignments that are past `draft`, or that ever had a layout,
 * get key_approved_at so grading that already runs never stops (§19.5).
 * assignments.subject_id stays NOT NULL until build step 4 (Classroom web
 * mirrors) needs it nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->char('sha256', 64);
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->unsignedSmallInteger('page_count');
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'sha256'], 'idx_documents_hash');
        });

        Schema::create('document_extractions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->char('input_hash', 64);
            $table->enum('purpose', ['answer_key', 'coursework', 'course', 'lesson_plan']);
            $table->enum('status', ['queued', 'done', 'failed'])->default('queued');
            $table->json('result')->nullable();
            $table->string('model', 64)->nullable();
            $table->string('prompt_version', 20)->nullable();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'input_hash', 'purpose'], 'uq_extraction');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->enum('mode', ['worksheet', 'freeform'])->default('worksheet');
            $table->enum('source', ['app', 'classroom_web'])->default('app');
            $table->boolean('accept_late')->default(true);
            $table->boolean('score_only')->default(false);
            $table->enum('key_origin', ['teacher', 'document', 'ai_draft'])->nullable();
            $table->timestamp('key_approved_at')->nullable();
            $table->foreignId('key_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('key_extraction_id')->nullable()->constrained('document_extractions')->nullOnDelete();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->text('model_answer')->nullable();
        });

        DB::table('assignments')
            ->where(fn ($q) => $q->where('status', '!=', 'draft')->orWhereNotNull('current_layout_version'))
            ->update(['key_approved_at' => DB::raw('COALESCE(updated_at, created_at, CURRENT_TIMESTAMP)'), 'key_origin' => 'teacher']);
    }

    public function down(): void
    {
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('model_answer');
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('key_extraction_id');
            $table->dropConstrainedForeignId('key_approved_by');
            $table->dropColumn(['mode', 'source', 'accept_late', 'score_only', 'key_origin', 'key_approved_at']);
        });

        Schema::dropIfExists('document_extractions');
        Schema::dropIfExists('source_documents');
    }
};
