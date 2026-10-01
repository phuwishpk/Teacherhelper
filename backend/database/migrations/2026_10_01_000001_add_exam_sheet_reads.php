<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.14 (Phase 10 build step 3, reading answer sheets):
 *
 * - exam_sheet_reads: the bubble fill the phone measured on one scanned
 *   answer-sheet page (one row per `scans` row of an exam), the version the
 *   server decided and the doubts it found;
 * - responses.exam_answer: what was read for one original question (sheet
 *   number, version, selected options in original positions or the numeric
 *   value, doubts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_sheet_reads', function (Blueprint $table) {
            $table->foreignId('scan_id')->primary()->constrained('scans')->cascadeOnDelete();
            $table->foreignId('assignment_id')->constrained('assignments');
            $table->json('version_fill')->nullable();
            $table->unsignedTinyInteger('version_no')->nullable();
            $table->enum('version_source', ['single', 'bubble', 'teacher', 'page_one'])->nullable();
            $table->json('rows_fill');
            $table->json('digits_fill')->nullable();
            $table->decimal('device_score', 6, 2)->nullable();
            $table->json('doubts')->nullable();
            $table->timestamps();
            $table->index(['assignment_id', 'version_no'], 'idx_sheet_reads_assignment');
        });

        Schema::table('responses', function (Blueprint $table) {
            $table->json('exam_answer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('responses', function (Blueprint $table) {
            $table->dropColumn('exam_answer');
        });
        Schema::dropIfExists('exam_sheet_reads');
    }
};
