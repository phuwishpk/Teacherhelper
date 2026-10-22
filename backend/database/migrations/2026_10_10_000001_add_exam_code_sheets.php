<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §22.19, decision #74 (10 Oct 2569): answer sheets on which the
 * student fills in their student ID, as a second way next to the QR printed
 * per student.
 *
 * - assignments.sheet_identity: how an answer sheet of the exam names its
 *   student, `qr` (the default, §22.6) or `code`; student_code_digits is the
 *   number of columns of the ID grid (4–13), set only with `code`. Both are
 *   structural (locked with structure_locked_at).
 * - exam_sheet_reads.identified_by: who named the student of the page (`qr`,
 *   `code` = the filled ID matched the roster, `teacher` = picked on the
 *   phone); student_code_read is the ID the server read from the fill
 *   (NULL when it could not be read, and on QR sheets).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->enum('sheet_identity', ['qr', 'code'])->default('qr');
            $table->unsignedTinyInteger('student_code_digits')->nullable();
        });

        Schema::table('exam_sheet_reads', function (Blueprint $table) {
            $table->enum('identified_by', ['qr', 'code', 'teacher'])->default('qr');
            $table->string('student_code_read', 13)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('exam_sheet_reads', function (Blueprint $table) {
            $table->dropColumn(['identified_by', 'student_code_read']);
        });

        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn(['sheet_identity', 'student_code_digits']);
        });
    }
};
