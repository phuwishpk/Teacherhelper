<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.1 `classroom_students`: membership + student_number (เลขที่).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_students', function (Blueprint $table) {
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->restrictOnDelete();
            $table->unsignedTinyInteger('student_number');
            $table->timestamps();
            $table->primary(['classroom_id', 'student_id']);
            $table->unique(['classroom_id', 'student_number'], 'uq_class_number');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_students');
    }
};
