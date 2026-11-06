<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.1 `classrooms`. class_code (6 chars) is what students type for PIN login.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classrooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->restrictOnDelete();
            $table->foreignId('teacher_id')->constrained('users')->restrictOnDelete();
            $table->string('name', 100);
            $table->unsignedTinyInteger('grade_level'); // ป.1 = 1 … ม.6 = 12
            $table->unsignedSmallInteger('academic_year'); // Buddhist era, e.g. 2569
            $table->char('class_code', 6)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classrooms');
    }
};
