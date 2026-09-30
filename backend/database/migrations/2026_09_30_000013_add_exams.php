<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.14 (Phase 10 build step 1, exams with answer sheets):
 *
 * - assignments: kind homework|exam, grading_method app|manual (exams only),
 *   version_count, duration_minutes, show_key_to_students,
 *   manual_full_marks, shuffle_nonce and structure_locked_at;
 * - exam_sections: the sections of an exam (mcq, true_false, numeric);
 * - questions: the exam types true_false and numeric, section_id,
 *   lock_options ("ห้ามสลับตัวเลือก"), approved_at, origin,
 *   copied_from_question_id and figure_source;
 * - question_options: the options of an exam mcq question in the original
 *   order (1–6 = ก–ฉ);
 * - exam_versions: the stored permutation of each shuffled version.
 *
 * The tables of later build steps (exam_page_images, exam_imports,
 * exam_sheet_reads, responses.exam_answer, worksheet_prints.kind) come with
 * those steps.
 */
return new class extends Migration
{
    private const TYPES = ['mcq', 'short', 'show_work', 'open'];

    private const TYPES_NEW = ['mcq', 'short', 'show_work', 'open', 'true_false', 'numeric'];

    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->enum('kind', ['homework', 'exam'])->default('homework');
            $table->enum('grading_method', ['app', 'manual'])->nullable();
            $table->unsignedTinyInteger('version_count')->default(1);
            $table->unsignedSmallInteger('duration_minutes')->nullable();
            $table->boolean('show_key_to_students')->default(false);
            $table->decimal('manual_full_marks', 6, 2)->nullable();
            $table->unsignedSmallInteger('shuffle_nonce')->default(0);
            $table->timestamp('structure_locked_at')->nullable();
        });

        Schema::create('exam_sections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->string('title')->nullable();
            $table->text('instructions')->nullable();
            $table->enum('type', ['mcq', 'true_false', 'numeric']);
            $table->unsignedTinyInteger('option_count')->nullable();
            $table->unsignedTinyInteger('numeric_digits')->nullable();
            $table->boolean('numeric_allow_negative')->default(false);
            $table->boolean('numeric_allow_decimal')->default(false);
            $table->decimal('default_points', 5, 2)->default(1);
            $table->timestamps();
            $table->unique(['assignment_id', 'position'], 'uq_section_position');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->enum('type', self::TYPES_NEW)->change();
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->constrained('exam_sections')->cascadeOnDelete();
            $table->boolean('lock_options')->default(false);
            $table->timestamp('approved_at')->nullable();
            $table->enum('origin', ['teacher', 'document', 'copied'])->nullable();
            $table->foreignId('copied_from_question_id')->nullable()->constrained('questions')->nullOnDelete();
            $table->json('figure_source')->nullable();
        });

        Schema::create('question_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->text('text')->nullable();
            $table->string('image_path')->nullable();
            $table->json('figure_source')->nullable();
            $table->timestamps();
            $table->unique(['question_id', 'position'], 'uq_option_position');
        });

        Schema::create('exam_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('version_no');
            $table->char('seed', 16);
            $table->char('structure_hash', 64);
            $table->json('question_order');
            $table->json('option_orders');
            $table->timestamps();
            $table->unique(['assignment_id', 'version_no'], 'uq_exam_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_versions');
        Schema::dropIfExists('question_options');
        // Exam questions cannot survive the narrower type enum.
        DB::table('questions')->whereNotNull('section_id')->delete();

        Schema::table('questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('copied_from_question_id');
            $table->dropConstrainedForeignId('section_id');
            $table->dropColumn(['lock_options', 'approved_at', 'origin', 'figure_source']);
        });
        Schema::table('questions', function (Blueprint $table) {
            $table->enum('type', self::TYPES)->change();
        });

        Schema::dropIfExists('exam_sections');

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn([
                'kind', 'grading_method', 'version_count', 'duration_minutes', 'show_key_to_students',
                'manual_full_marks', 'shuffle_nonce', 'structure_locked_at',
            ]);
        });
    }
};
