<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.6 `model_versions`: the on-device digit models (§12) an admin
 * uploads and activates; the app downloads the active one (§9.8). The
 * .tflite file lives on the private disk (§7.3 models/{name}/{version}.tflite),
 * metrics holds the exported metrics.json (decode contract included).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('model_versions', function (Blueprint $table) {
            $table->id();
            $table->string('name', 40);
            $table->string('version', 20);
            $table->string('file_path');
            $table->char('sha256', 64);
            $table->json('metrics');
            $table->boolean('is_active')->default(false);
            $table->timestamps();
            $table->unique(['name', 'version'], 'uq_model');
            $table->index(['name', 'is_active'], 'idx_model_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_versions');
    }
};
