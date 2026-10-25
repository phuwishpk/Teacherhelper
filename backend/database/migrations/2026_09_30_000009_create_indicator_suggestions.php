<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §20.3 / §20.6 (Phase 9 build step 9): Gemini's indicator
 * suggestions for the questions of an assignment linked to a lesson plan.
 * They are only proposals: the teacher confirms or edits them and
 * PUT /assignments/{id}/indicator-mapping writes question_skill.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('indicator_suggestions', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->string('reason_th')->nullable();
            $table->timestamp('created_at');
            $table->primary(['question_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('indicator_suggestions');
    }
};
