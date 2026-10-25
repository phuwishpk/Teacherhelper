<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 / §21.7 item 6 (Phase 8 build step 7): explanation_cache.
 * One explanation per (question, normalised wrong answer); a later answer
 * with the same key reuses it instead of calling Gemini. answer_hash is the
 * SHA-256 of the key with a prefix per kind of explanation (short answer /
 * final answer / every show_work line) together with a fingerprint of the
 * question and its rubric, so an edited question stops reusing old texts.
 * source teacher always beats ai.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('explanation_cache', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->char('answer_hash', 64);
            $table->text('explanation');
            $table->enum('source', ['ai', 'teacher']);
            $table->foreignId('response_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('updated_at');
            $table->primary(['question_id', 'answer_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('explanation_cache');
    }
};
