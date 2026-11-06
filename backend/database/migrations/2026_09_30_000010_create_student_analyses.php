<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §20.5 / §20.6 / §20.8 (Phase 9 build step 11): the per-student
 * analysis of a classroom. Code writes the strengths and areas when a
 * submission is published; Gemini writes a teacher and a student text
 * (nightly through the Batch API, or "วิเคราะห์ตอนนี้"); students see only
 * what the teacher approved, or every new text when the classroom shares
 * automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->boolean('auto_share_analysis')->default(false);
        });

        Schema::create('analysis_batches', function (Blueprint $table) {
            $table->id();
            // NULL = the server key (GEMINI_API_KEY).
            $table->foreignId('key_owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('batch_name', 128)->nullable();
            $table->enum('state', ['building', 'submitted', 'running', 'succeeded', 'failed', 'expired', 'cancelled', 'collected'])->default('building');
            $table->unsignedSmallInteger('request_count');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('last_polled_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
            $table->index('state', 'idx_batches_state');
        });

        Schema::create('student_analyses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->char('computed_input_hash', 64);
            $table->char('queued_input_hash', 64)->nullable();
            $table->char('generated_input_hash', 64)->nullable();
            $table->json('strengths');
            $table->json('areas');
            $table->enum('status', ['computed', 'queued', 'drafted', 'failed'])->default('computed');
            $table->text('teacher_text')->nullable();
            $table->text('student_text')->nullable();
            $table->json('next_step_skill_ids')->nullable();
            $table->enum('generated_via', ['batch', 'now'])->nullable();
            $table->foreignId('batch_id')->nullable()->constrained('analysis_batches')->nullOnDelete();
            $table->timestamp('generated_at')->nullable();
            $table->text('shared_student_text')->nullable();
            $table->timestamp('shared_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'classroom_id'], 'uq_analysis');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_analyses');
        Schema::dropIfExists('analysis_batches');
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropColumn('auto_share_analysis');
        });
    }
};
