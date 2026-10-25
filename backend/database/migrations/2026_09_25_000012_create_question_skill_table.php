<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** DESIGN §8.3 `question_skill` (the Q-matrix of §2.3). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('question_skill', function (Blueprint $table) {
            $table->foreignId('question_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->primary(['question_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('question_skill');
    }
};
