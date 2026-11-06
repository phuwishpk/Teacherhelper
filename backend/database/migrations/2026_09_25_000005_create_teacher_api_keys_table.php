<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §8.1 `teacher_api_keys`: a teacher's own Gemini key, stored with the
 * Laravel encrypter and never returned to a client (§10.1). The /me/ai-key
 * endpoints arrive with the Gemini step; this table is created now so the
 * schema is complete.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('teacher_api_keys', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->enum('provider', ['gemini'])->default('gemini');
            $table->text('encrypted_key');
            $table->char('key_last4', 4);
            $table->timestamp('last_verified_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('teacher_api_keys');
    }
};
