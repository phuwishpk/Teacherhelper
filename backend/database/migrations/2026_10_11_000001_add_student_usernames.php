<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §29.10: students sign in with a username and a password.
 *
 * - users.username: unique across the installation, set for every student
 *   (the student code when it is usable and free, otherwise s + 7 digits);
 * - student_credentials.must_change_password: the password is the initial
 *   one (123456) and must be replaced at the next sign-in. Existing students
 *   keep their 6-digit PIN as their password and are not asked to change it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username', 40)->nullable()->unique();
        });
        Schema::table('student_credentials', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });

        $taken = [];
        foreach (DB::table('users')->where('role', 'student')->orderBy('id')->get(['id', 'student_code']) as $student) {
            $username = strtolower(trim((string) $student->student_code));
            if (preg_match('/^[a-z0-9][a-z0-9._-]{2,39}$/', $username) !== 1 || isset($taken[$username])) {
                do {
                    $username = 's'.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
                } while (isset($taken[$username]));
            }
            $taken[$username] = true;
            DB::table('users')->where('id', $student->id)->update(['username' => $username]);
        }
    }

    public function down(): void
    {
        Schema::table('student_credentials', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
