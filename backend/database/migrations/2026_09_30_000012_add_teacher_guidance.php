<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §21.12 (30 ก.ย. 2569): the teacher's guidance to the AI.
 *
 * - ai_calls.teacher_guidance / guidance_by: every call made with guidance
 *   records the text and its author (the one part of a prompt that is
 *   logged, §21.8);
 * - document_extractions.guidance: the guidance a read or draft was made
 *   with (it is part of the row's input_hash), shown as "คำแนะนำที่ใช้";
 * - student_analyses.guidance: the guidance of the latest "วิเคราะห์ตอนนี้"
 *   that wrote the texts (NULL after a nightly batch wrote them).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->text('teacher_guidance')->nullable();
            $table->foreignId('guidance_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->text('guidance')->nullable();
        });
        Schema::table('student_analyses', function (Blueprint $table) {
            $table->text('guidance')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('student_analyses', function (Blueprint $table) {
            $table->dropColumn('guidance');
        });
        Schema::table('document_extractions', function (Blueprint $table) {
            $table->dropColumn('guidance');
        });
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropConstrainedForeignId('guidance_by');
            $table->dropColumn('teacher_guidance');
        });
    }
};
