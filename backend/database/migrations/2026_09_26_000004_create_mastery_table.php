<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.5 `mastery`: the EWMA of a student's observations per skill
 * (§14.2), recomputed from skill_observations whenever one is added or a
 * published score changes. Composite primary key, no auto-increment id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mastery', function (Blueprint $table) {
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('skill_id')->constrained()->restrictOnDelete();
            $table->decimal('value', 4, 3);
            $table->unsignedSmallInteger('n_obs');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();
            $table->primary(['student_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mastery');
    }
};
