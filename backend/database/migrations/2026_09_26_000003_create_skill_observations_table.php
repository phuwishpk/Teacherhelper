<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.5 `skill_observations`: one row per (published answer, skill)
 * and per (practice attempt, skill), the input of the EWMA mastery (§14.2)
 * and of the BKT export (§14.4). A published answer yields one row per skill
 * of its question; republishing replaces those rows (unique per response
 * and skill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->enum('source', ['homework', 'practice']);
            $table->foreignId('response_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('practice_attempt_id')->nullable()->constrained()->restrictOnDelete();
            $table->decimal('score_ratio', 4, 3);
            $table->timestamp('observed_at')->useCurrent();
            $table->timestamps();
            $table->index(['student_id', 'skill_id', 'observed_at'], 'idx_obs');
            $table->unique(['response_id', 'skill_id'], 'uq_obs_response_skill');
            $table->unique(['practice_attempt_id', 'skill_id'], 'uq_obs_attempt_skill');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_observations');
    }
};
