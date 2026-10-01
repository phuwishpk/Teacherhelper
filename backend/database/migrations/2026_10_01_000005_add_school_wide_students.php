<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §24.3 A (build 1): one student account per school.
 *
 * - users.student_code: the optional school student ID (normalised by
 *   StudentCode), unique per school; MariaDB's UNIQUE lets any number of
 *   NULLs through, so students without an ID are unlimited.
 * - users.merged_into_id: the account was merged into that one (§24.5).
 * - classrooms.closed_at / closed_by: a closed classroom ("ห้องเก่า") is
 *   read-only (§24.6).
 * - student_merges: the audit row of each merge, with the moved ids and the
 *   dropped rows in `summary` (there is no undo).
 *
 * Data (§24.15): a student without school_id (there should be none) gets the
 * school of their first classroom; a student in classrooms of two schools is
 * logged and skipped.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('student_code', 20)->nullable();
            $table->foreignId('merged_into_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->unique(['school_id', 'student_code'], 'uq_users_student_code');
        });

        Schema::table('classrooms', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->index(['teacher_id', 'closed_at'], 'idx_classrooms_teacher_open');
        });

        Schema::create('student_merges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('kept_student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('merged_student_id')->unique()->constrained('users')->restrictOnDelete();
            $table->foreignId('merged_by')->constrained('users')->restrictOnDelete();
            $table->json('summary');
            $table->timestamp('created_at')->useCurrent(); // explicit default, see SchemaTest
            $table->index('kept_student_id', 'idx_merges_kept');
        });

        $this->fillStudentSchools();
    }

    public function down(): void
    {
        Schema::dropIfExists('student_merges');

        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropIndex('idx_classrooms_teacher_open');
            $table->dropConstrainedForeignId('closed_by');
            $table->dropColumn('closed_at');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('uq_users_student_code');
            $table->dropConstrainedForeignId('merged_into_id');
            $table->dropColumn('student_code');
        });
    }

    private function fillStudentSchools(): void
    {
        $orphans = DB::table('users')->where('role', 'student')->whereNull('school_id')->pluck('id');
        foreach ($orphans as $studentId) {
            $schools = DB::table('classroom_students')
                ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
                ->where('classroom_students.student_id', $studentId)
                ->orderBy('classrooms.id')
                ->pluck('classrooms.school_id')
                ->unique()
                ->values();
            if ($schools->count() === 1) {
                DB::table('users')->where('id', $studentId)->update(['school_id' => $schools->first()]);
            } elseif ($schools->count() > 1) {
                Log::warning('migrate.student_in_two_schools', ['student_id' => $studentId, 'school_ids' => $schools->all()]);
            }
        }
    }
};
