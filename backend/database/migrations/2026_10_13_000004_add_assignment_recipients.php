<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §30.13 (homework for chosen students, step 3ข):
 *
 * - assignments.audience: all (the classroom) or students (the rows of
 *   assignment_recipients). A student the work does not reach neither sees
 *   it nor is counted for it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->enum('audience', ['all', 'students'])->default('all');
        });

        Schema::create('assignment_recipients', function (Blueprint $table) {
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['assignment_id', 'student_id']);
            $table->index('student_id', 'idx_assignment_recipient_student');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_recipients');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropColumn('audience');
        });
    }
};
