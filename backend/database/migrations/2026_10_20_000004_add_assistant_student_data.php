<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §37.8 (assistant step 4): questions about students' results.
 *
 * - assistant_student_labels: the label of a student in one conversation
 *   ("S1"); Gemini sees the label, the app shows the name. No foreign key
 *   to users (like ai_calls.user_id, §31.1): a deleted student's label just
 *   stops resolving, and it goes with its conversation.
 * - schools.assistant_student_data: the admin's switch per workspace (on).
 * - users.assistant_data_ack_at: when the teacher pressed "เข้าใจแล้ว".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_student_labels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            $table->string('label', 8);
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('classroom_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->unique(['conversation_id', 'label']);
            $table->unique(['conversation_id', 'student_id']);
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('assistant_student_data')->default(true);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('assistant_data_ack_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('assistant_data_ack_at');
        });
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('assistant_student_data');
        });
        Schema::dropIfExists('assistant_student_labels');
    }
};
