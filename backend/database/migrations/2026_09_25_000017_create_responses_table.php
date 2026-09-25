<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.4 `responses`: one per (submission, question). A rescan of the
 * page updates the same row (new scan_id, crops, readings) and resets the
 * grading fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->restrictOnDelete();
            $table->foreignId('question_id')->constrained()->restrictOnDelete();
            $table->foreignId('scan_id')->constrained()->restrictOnDelete();
            $table->string('crop_path')->nullable();
            $table->string('final_crop_path')->nullable();
            $table->float('ink_ratio')->nullable();
            $table->json('mcq_fill')->nullable();
            $table->string('cnn_text', 32)->nullable();
            $table->float('cnn_confidence')->nullable();
            $table->enum('grading_state', ['queued', 'extracted', 'scored', 'failed', 'manual'])->default('queued');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('extraction')->nullable();
            $table->json('fuzzy_trace')->nullable();
            $table->decimal('ai_score', 5, 2)->nullable();
            $table->enum('ai_understanding', ['good', 'partial', 'not_yet'])->nullable();
            $table->json('ai_error_types')->nullable();
            $table->float('review_priority')->nullable();
            $table->enum('priority_band', ['check', 'look', 'confident'])->nullable();
            $table->decimal('final_score', 5, 2)->nullable();
            $table->enum('final_understanding', ['good', 'partial', 'not_yet'])->nullable();
            $table->json('final_error_types')->nullable();
            $table->text('explanation')->nullable();
            $table->boolean('explanation_edited')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->unique(['submission_id', 'question_id'], 'uq_response');
            $table->index('grading_state', 'idx_responses_state');
            $table->index(['submission_id', 'review_priority'], 'idx_responses_queue');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('responses');
    }
};
