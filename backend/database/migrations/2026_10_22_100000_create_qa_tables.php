<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Krucheck Test Lab at /qa (DESIGN §39): the people who test the system,
 * their results per test case, and their SUS answers. Separate from users:
 * a tester sees no school data and a Krucheck account opens nothing here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qa_testers', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('password');
            $table->boolean('is_lead')->default(false);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('qa_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_tester_id')->constrained('qa_testers')->cascadeOnDelete();
            $table->string('case_code', 20);
            $table->string('status', 20)->nullable();
            $table->unsignedInteger('time_sec')->nullable();
            $table->unsignedSmallInteger('error_count')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['qa_tester_id', 'case_code']);
            $table->index('case_code');
        });

        Schema::create('qa_sus_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_tester_id')->unique()->constrained('qa_testers')->cascadeOnDelete();
            $table->string('role', 10);
            $table->json('answers');
            $table->decimal('score', 5, 1);
            $table->text('comment')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qa_sus_responses');
        Schema::dropIfExists('qa_results');
        Schema::dropIfExists('qa_testers');
    }
};
