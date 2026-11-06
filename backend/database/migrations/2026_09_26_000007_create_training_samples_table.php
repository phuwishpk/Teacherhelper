<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.6 `training_samples`: labelled handwriting crops for the digit
 * model (§12.3). teacher_correction rows are written only for schools with
 * allow_training_data = TRUE, and the crop is copied to training/ so the
 * crop retention policy (§7.3) never deletes it. One sample per response.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('training_samples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('source', ['collection_sheet', 'teacher_correction']);
            $table->string('crop_path');
            $table->string('label', 32);
            $table->string('writer_key', 64)->nullable();
            $table->foreignId('response_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->timestamps();
            $table->index(['school_id', 'source'], 'idx_samples_school_source');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('training_samples');
    }
};
