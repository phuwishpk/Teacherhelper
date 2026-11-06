<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.3 `questions`. answer_key shape depends on `type` (validated by
 * App\Domain\Assignments\QuestionData); `open` questions keep it NULL and use
 * rubric_criteria instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->enum('type', ['mcq', 'short', 'show_work', 'open']);
            $table->text('prompt_text');
            $table->string('prompt_image_path')->nullable();
            $table->decimal('max_points', 5, 2);
            $table->unsignedTinyInteger('answer_lines')->nullable();
            $table->boolean('is_numeric')->default(false);
            $table->enum('match_mode', ['flexible', 'exact'])->default('flexible');
            $table->json('answer_key')->nullable();
            $table->enum('rubric_status', ['not_needed', 'draft', 'approved'])->default('not_needed');
            $table->timestamps();
            $table->unique(['assignment_id', 'position'], 'uq_question_position');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('questions');
    }
};
