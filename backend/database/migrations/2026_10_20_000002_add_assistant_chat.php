<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §37 (step 1): "ผู้ช่วย Krucheck", a teacher's chat with Gemini about
 * using the system. Conversations and their messages are kept 90 days after
 * the last message (eduvision:purge-images) and go with the teacher's
 * account. ai_calls.purpose gets assistant_chat.
 */
return new class extends Migration
{
    private const CALL_PURPOSES = [
        'extract', 'extract_batch', 'extract_page', 'rubric_draft', 'explanation', 'practice_gen',
        'answer_key_read', 'answer_key_draft', 'document_read', 'indicator_suggest', 'student_analysis', 'exam_read',
    ];

    public function up(): void
    {
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();
            $table->index(['user_id', 'updated_at'], 'idx_assistant_conversations_user');
            $table->index('updated_at', 'idx_assistant_conversations_updated');
        });

        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
            // user: the teacher; assistant: the answer; error: a failed answer (code and call id).
            $table->enum('role', ['user', 'assistant', 'error']);
            $table->text('content');
            $table->string('error_code', 20)->nullable();
            $table->unsignedBigInteger('ai_call_id')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['conversation_id', 'id'], 'idx_assistant_messages_conversation');
        });

        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', [...self::CALL_PURPOSES, 'assistant_chat'])->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
        DB::table('ai_calls')->where('purpose', 'assistant_chat')->delete();
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->enum('purpose', self::CALL_PURPOSES)->change();
        });
    }
};
