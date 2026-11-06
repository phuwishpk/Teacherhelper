<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §36: the error code of a failed AI call (AI-QUOTA, AI-KEY-INVALID,
 * ...), the HTTP status Google answered, and the teacher the call was for,
 * so the code and id a teacher reports lead straight to the call.
 * user_id has no foreign key: SQLite could only drop it again by rebuilding
 * ai_calls, and the row is a log line.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->string('error_code', 20)->nullable()->index('idx_ai_calls_error_code');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index('idx_ai_calls_user');
        });
    }

    public function down(): void
    {
        Schema::table('ai_calls', function (Blueprint $table) {
            $table->dropIndex('idx_ai_calls_error_code');
            $table->dropIndex('idx_ai_calls_user');
            $table->dropColumn(['error_code', 'http_status', 'user_id']);
        });
    }
};
