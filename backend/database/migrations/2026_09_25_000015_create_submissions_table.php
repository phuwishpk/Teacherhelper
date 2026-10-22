<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.4 `submissions`: one per (assignment, student), created by the
 * first scan of any of the student's pages. Status transitions live in
 * App\Domain\Scans\SubmissionStatus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['awaiting_scan', 'grading', 'needs_review', 'reviewed', 'published'])->default('awaiting_scan');
            $table->decimal('total_score', 6, 2)->nullable();
            $table->timestamp('published_at')->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['assignment_id', 'student_id'], 'uq_submission');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};
