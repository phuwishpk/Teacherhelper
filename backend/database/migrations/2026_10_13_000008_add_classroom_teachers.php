<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.13.4 (step 3ค): co-teaching by invitation. The owner invites a
 * colleague by e-mail (`pending`); the colleague accepts (`accepted`, the
 * row then names them) or declines. An accepted row makes them a subject
 * teacher of the classroom who binds their own courses to it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_teachers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->foreignId('teacher_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->enum('status', ['pending', 'accepted', 'declined'])->default('pending');
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['classroom_id', 'email'], 'uq_classroom_teacher_email');
            $table->index(['teacher_id', 'status'], 'idx_classroom_teachers_teacher');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_teachers');
    }
};
