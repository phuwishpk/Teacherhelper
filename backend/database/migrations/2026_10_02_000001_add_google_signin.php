<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §24.3 C (build 3): Google sign-in for every role.
 *
 * - user_google_identities: the one Google account a user signs in with
 *   (claim `sub` of the ID token, unique across users). Separate from
 *   google_accounts (§18.4), which holds the Classroom refresh token: the
 *   account used to sign in and the account linked to Classroom may differ.
 *   No token of any kind is stored here (§24.14).
 * - schools.google_signin_domains: the allowed e-mail domains (NULL or [] =
 *   any domain); schools.student_google_signin: the PDPA switch for the
 *   school's students, off by default (§24.15 step 4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_google_identities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('google_sub', 64)->unique();
            $table->string('email', 255);
            $table->string('name', 255)->nullable();
            $table->string('picture_url', 512)->nullable();
            $table->enum('linked_via', ['teacher_email', 'registration', 'self', 'pin_confirm', 'classroom_roster']);
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notice_version', 20)->nullable();
            // An explicit default: without it MariaDB < 10.10 adds ON UPDATE
            // CURRENT_TIMESTAMP and every sign-in would overwrite linked_at.
            $table->timestamp('linked_at')->useCurrent();
            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->json('google_signin_domains')->nullable();
            $table->boolean('student_google_signin')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['google_signin_domains', 'student_google_signin']);
        });

        Schema::dropIfExists('user_google_identities');
    }
};
