<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.5 `learning_resources`: review links a teacher adds to a skill,
 * shown to students under their practice items (§14.1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('learning_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->string('title');
            $table->string('url', 2048);
            $table->foreignId('added_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'skill_id'], 'idx_resources_school_skill');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_resources');
    }
};
