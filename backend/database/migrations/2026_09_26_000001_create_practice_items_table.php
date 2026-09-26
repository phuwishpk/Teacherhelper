<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.5 `practice_items`: the school's remedial practice bank, one
 * item per skill, drafted by Gemini (§10.6) or written by a teacher and
 * approved by a teacher before students see it (§14.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('practice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->enum('answer_type', ['numeric', 'short', 'mcq']);
            $table->text('prompt_text');
            $table->json('options')->nullable();
            $table->json('answer_key');
            $table->text('explanation');
            $table->enum('status', ['draft', 'approved', 'retired'])->default('draft');
            $table->enum('source', ['ai', 'teacher']);
            $table->foreignId('approved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'skill_id', 'status'], 'idx_practice');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('practice_items');
    }
};
