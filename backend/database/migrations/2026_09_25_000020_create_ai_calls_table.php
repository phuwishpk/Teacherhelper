<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.4 `ai_calls`: one row per Gemini request for cost and quality
 * tracking. key_source records whose key paid for it (§10.1). Never stores
 * images or prompt text. No updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_calls', function (Blueprint $table) {
            $table->id();
            $table->enum('purpose', ['extract', 'rubric_draft', 'explanation', 'practice_gen']);
            // An audit log must never block deleting what it refers to (a question
            // with a rubric draft, a draft assignment): the link becomes NULL.
            $table->foreignId('response_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model', 64);
            $table->string('prompt_version', 20);
            $table->enum('key_source', ['teacher', 'server']);
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->enum('status', ['ok', 'error', 'invalid_output']);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_calls');
    }
};
