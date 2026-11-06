<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Test Lab (DESIGN §39.6): a function-level usability task carries the
 * alternative paths of its use case ("กรณีอื่น"); the tester marks each one
 * passed or failed. JSON map: label ("3ก") => "pass" | "fail".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_results', function (Blueprint $table) {
            $table->json('checks')->nullable()->after('note');
        });
    }

    public function down(): void
    {
        Schema::table('qa_results', function (Blueprint $table) {
            $table->dropColumn('checks');
        });
    }
};
