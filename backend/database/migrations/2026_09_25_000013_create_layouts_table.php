<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.3 `layouts`: `pages` is the array of per-page layout JSON (§5.3)
 * the server prints from and the phone crops with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('layouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('pages');
            $table->timestamps();
            $table->unique(['assignment_id', 'version'], 'uq_layout_version');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('layouts');
    }
};
