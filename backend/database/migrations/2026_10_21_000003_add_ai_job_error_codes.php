<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §36.1: the AI error code (AiErrorCode) and the ai_calls id of a
 * failed background read or analysis, shown to the teacher like the codes
 * of §36. No foreign key to ai_calls (the log is pruned on its own).
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['document_extractions', 'student_analyses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->string('error_code', 20)->nullable();
                $table->unsignedBigInteger('ai_call_id')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['document_extractions', 'student_analyses'] as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn(['error_code', 'ai_call_id']);
            });
        }
    }
};
