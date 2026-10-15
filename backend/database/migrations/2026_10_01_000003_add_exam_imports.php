<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.4, §22.14 (Phase 10 build step 5, reading an exam file):
 *
 * - exam_page_images: pages of the teacher's exam documents the figures are
 *   cropped from (decoded by the server from a photo, or rendered and
 *   uploaded by the app for a PDF or HEIC);
 * - exam_imports: every read the teacher asked for (which files, in which
 *   order and range, by whom), applied once the read is done; also the
 *   proof that lets the owner download the source files again;
 * - questions.lock_options_suggested: Gemini's "ห้ามสลับตัวเลือก" hint kept
 *   as a suggestion (added to DESIGN §22.14 with this step);
 * - document_extractions.purpose exam and ai_calls.purpose exam_read.
 */
return new class extends Migration
{
    private const EXTRACTION_PURPOSES = ['answer_key', 'coursework', 'course', 'lesson_plan'];

    private const CALL_PURPOSES = [
        'extract', 'extract_batch', 'extract_page', 'rubric_draft', 'explanation', 'practice_gen',
        'answer_key_read', 'answer_key_draft', 'document_read', 'indicator_suggest', 'student_analysis',
    ];

    public function up(): void
    {
        Schema::create('exam_page_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_document_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('page_no');
            $table->string('file_path')->nullable();
            $table->unsignedSmallInteger('width_px');
            $table->unsignedSmallInteger('height_px');
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'source_document_id', 'page_no'], 'uq_exam_page');
        });

        Schema::create('exam_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('extraction_id')->nullable()->constrained('document_extractions')->nullOnDelete();
            $table->json('documents');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
            $table->index('assignment_id', 'idx_exam_imports');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->boolean('lock_options_suggested')->default(false);
        });

        Schema::table('document_extractions', function (Blueprint $table) {
            $table->enum('purpose', [...self::EXTRACTION_PURPOSES, 'exam'])->change();
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', [...self::CALL_PURPOSES, 'exam_read'])->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_imports');
        Schema::dropIfExists('exam_page_images');
        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('lock_options_suggested');
        });
        DB::table('document_extractions')->where('purpose', 'exam')->delete();
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->enum('purpose', self::EXTRACTION_PURPOSES)->change();
        });
        DB::table('ai_calls')->where('purpose', 'exam_read')->delete();
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', self::CALL_PURPOSES)->change();
        });
    }
};
