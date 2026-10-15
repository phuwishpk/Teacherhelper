<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §24.3 B (build 2): shared homerooms.
 *
 * A subject teacher asks the homeroom teacher of a classroom to bind one of
 * their courses to it; an admin may bind one directly (origin = admin,
 * approved at once) and Google Classroom import (build 4) asks with origin
 * classroom_import. course_classroom (§20.6) stays the source of truth of
 * "this course is taught in this classroom": a row whose course creator is
 * not the homeroom teacher is a subject teacher, so no column is added there.
 *
 * Duplicate pending requests of one (classroom, course) are prevented in
 * code under Cache::lock (MariaDB has no partial unique index).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_course_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('origin', ['teacher', 'classroom_import', 'admin'])->default('teacher');
            $table->enum('status', ['pending', 'approved', 'declined', 'cancelled'])->default('pending');
            $table->string('message', 255)->nullable();
            $table->string('google_course_id', 64)->nullable();
            $table->string('google_course_name', 255)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->string('decline_reason', 255)->nullable();
            $table->timestamps();
            $table->index(['classroom_id', 'status'], 'idx_requests_classroom');
            $table->index(['requested_by', 'status'], 'idx_requests_requester');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_course_requests');
    }
};
