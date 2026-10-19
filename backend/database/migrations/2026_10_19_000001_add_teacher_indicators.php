<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §34: the indicators a teacher teaches ("ตัวชี้วัดของฉัน"). The
 * indicator pickers show these (and the teacher's own indicators) first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_indicators', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamp('created_at')->nullable();
            $table->primary(['user_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_indicators');
    }
};
