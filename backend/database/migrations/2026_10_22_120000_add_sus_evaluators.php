<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SUS by its own people (DESIGN §39.7): qa_testers gets a role (`tester`
 * opens /qa, `evaluator` opens /sus only), and a SUS response belongs to an
 * evaluator per role of the app they used (teacher or student), with
 * Bangor's adjective rating and how they used it. The responses testers
 * gave before are dropped: under the standard they are not SUS data
 * (testers know the system and were not one-time users of it).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('qa_testers', function (Blueprint $table) {
            $table->string('role', 20)->default('tester')->after('email');
        });

        Schema::dropIfExists('qa_sus_responses');
        Schema::create('qa_sus_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_tester_id')->constrained('qa_testers')->cascadeOnDelete();
            $table->string('role', 10);
            $table->json('answers');
            $table->decimal('score', 5, 1);
            $table->unsignedTinyInteger('adjective')->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('experience', 20)->nullable();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['qa_tester_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_sus_responses');
        Schema::create('qa_sus_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_tester_id')->unique()->constrained('qa_testers')->cascadeOnDelete();
            $table->string('role', 10);
            $table->json('answers');
            $table->decimal('score', 5, 1);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
        Schema::table('qa_testers', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
