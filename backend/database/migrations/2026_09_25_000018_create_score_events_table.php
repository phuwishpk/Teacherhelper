<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.4 `score_events`: an append-only log of every score change, the
 * main data for the AI-bias analysis (§13). No updated_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained()->restrictOnDelete();
            $table->enum('actor', ['ai', 'teacher', 'system']);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->enum('action', ['ai_scored', 'override', 'bulk_approve', 'appeal_accepted', 'appeal_rejected', 'rescan']);
            $table->decimal('old_score', 5, 2)->nullable();
            $table->decimal('new_score', 5, 2)->nullable();
            $table->enum('old_understanding', ['good', 'partial', 'not_yet'])->nullable();
            $table->enum('new_understanding', ['good', 'partial', 'not_yet'])->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_events');
    }
};
