<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DESIGN §8.3 `rubric_criteria`: exactly one is_core per approved rubric. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rubric_criteria', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position');
            $table->text('description');
            $table->decimal('points', 5, 2);
            $table->boolean('is_core')->default(false);
            $table->enum('source', ['ai', 'teacher']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rubric_criteria');
    }
};
