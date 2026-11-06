<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks one queued render of student QR login cards (DESIGN §9.2
 * POST /classrooms/{id}/login-cards and POST /students/{id}/login-card).
 *
 * Not in DESIGN §8: the design queues the job but names no table for its
 * status. It mirrors `worksheet_prints` (§8.3) so the app polls both the same
 * way. Exactly one of classroom_id / student_id is set.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_card_prints', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['queued', 'rendering', 'ready', 'failed'])->default('queued');
            $table->string('file_path')->nullable();
            $table->string('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_card_prints');
    }
};
