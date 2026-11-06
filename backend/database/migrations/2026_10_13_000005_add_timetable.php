<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.12 (the weekly timetable, step 2ข):
 *
 * - timetable_slots: a course taught in a classroom on a weekday, in a
 *   period (number, start and end optional);
 * - timetable_cancellations: one date a slot does not happen;
 * - courses.term_starts_on / term_ends_on: the term the timetable covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('weekday'); // 1 = Monday … 7 = Sunday
            $table->unsignedTinyInteger('period_no')->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->index(['classroom_id', 'weekday'], 'idx_timetable_classroom_day');
            $table->index(['course_id', 'weekday'], 'idx_timetable_course_day');
        });

        Schema::create('timetable_cancellations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('timetable_slot_id')->constrained()->cascadeOnDelete();
            $table->date('held_on');
            $table->string('note', 255)->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->unique(['timetable_slot_id', 'held_on'], 'uq_timetable_cancellation');
        });

        Schema::table('courses', function (Blueprint $table) {
            $table->date('term_starts_on')->nullable();
            $table->date('term_ends_on')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn(['term_starts_on', 'term_ends_on']);
        });
        Schema::dropIfExists('timetable_cancellations');
        Schema::dropIfExists('timetable_slots');
    }
};
