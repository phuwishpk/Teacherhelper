<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.3 `worksheet_prints`: one queued PDF of a whole classroom's
 * worksheets (RenderWorksheetsJob in batches, then MergeWorksheetsJob).
 *
 * `error` is not in DESIGN §8.3. It mirrors login_card_prints.error so the app
 * can show why a render failed (for example the assignment was edited after
 * the print was queued).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('worksheet_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('layout_version');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['queued', 'rendering', 'ready', 'failed'])->default('queued');
            $table->string('file_path')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worksheet_prints');
    }
};
