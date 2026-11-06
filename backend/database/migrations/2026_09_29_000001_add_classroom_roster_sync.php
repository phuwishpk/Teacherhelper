<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 part A (import a classroom from Google Classroom, roster sync):
 * - classroom_students.left_course_at: the account left the course; the
 *   student stays (with their scores) and is shown "ไม่อยู่ใน Classroom แล้ว";
 * - classroom_google_ignored_users: accounts the teacher removed at import,
 *   never added back by a roster sync;
 * - classroom_google_links.roster_synced_at / work_synced_at: when the last
 *   roster sync and the last work sync (§19.3) finished.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->timestamp('left_course_at')->nullable();
        });

        Schema::create('classroom_google_ignored_users', function (Blueprint $table) {
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->string('google_user_id', 64);
            $table->string('name');
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['classroom_id', 'google_user_id']);
        });

        Schema::table('classroom_google_links', function (Blueprint $table) {
            $table->timestamp('roster_synced_at')->nullable();
            $table->timestamp('work_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classroom_google_links', function (Blueprint $table) {
            $table->dropColumn(['roster_synced_at', 'work_synced_at']);
        });
        Schema::dropIfExists('classroom_google_ignored_users');
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->dropColumn('left_course_at');
        });
    }
};
