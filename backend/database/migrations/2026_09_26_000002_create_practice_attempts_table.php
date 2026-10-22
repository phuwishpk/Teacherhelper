<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.5 `practice_attempts`: a student's typed answer to a practice
 * item, graded at once (§14.1). Append-only: created_at, no updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('practice_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->text('answer');
            $table->decimal('score_ratio', 4, 3);
            $table->timestamp('created_at')->useCurrent();
            $table->index(['student_id', 'practice_item_id', 'created_at'], 'idx_attempts_student_item');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_attempts');
    }
};
