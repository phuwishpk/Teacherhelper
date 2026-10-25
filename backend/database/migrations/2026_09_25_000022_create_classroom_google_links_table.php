<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4 `classroom_google_links`: the Google Classroom course a
 * classroom is linked to. owner_user_id is the teacher whose Google account
 * made the link.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('classroom_google_links', function (Blueprint $table) {
            $table->foreignId('classroom_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('course_id', 64);
            $table->string('course_name');
            $table->foreignId('owner_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('linked_at')->useCurrent();
            $table->index('course_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_google_links');
    }
};
