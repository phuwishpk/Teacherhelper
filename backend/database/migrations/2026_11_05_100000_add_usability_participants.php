<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outside participants of the short usability set sign themselves up at
 * /usability (DESIGN §39.10): qa_testers gets their occupation, a task
 * result gets the participant's own ease rating (1-5), and qa_settings
 * keeps the group's sign-up code (hashed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_testers', function (Blueprint $table) {
            $table->string('occupation', 100)->nullable()->after('name');
        });

        Schema::table('qa_results', function (Blueprint $table) {
            $table->unsignedTinyInteger('ease')->nullable()->after('error_count');
        });

        Schema::create('qa_settings', function (Blueprint $table) {
            $table->string('key', 50)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_settings');
        Schema::table('qa_results', function (Blueprint $table) {
            $table->dropColumn('ease');
        });
        Schema::table('qa_testers', function (Blueprint $table) {
            $table->dropColumn('occupation');
        });
    }
};
