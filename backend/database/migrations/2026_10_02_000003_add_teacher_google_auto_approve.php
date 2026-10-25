<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §24.9.3, decision #71 (2 Oct 2569): a teacher whose Google account
 * is unknown is created active and signed in at once when the school allows
 * it.
 *
 * - schools.teacher_google_auto_approve: the per-school switch, on by
 *   default (the user's choice); off = the pending registration of #70.
 * - user_google_identities.linked_via gains `google_signup`, the marker of
 *   an account created by Google sign-in (no column on users is needed).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('teacher_google_auto_approve')->default(true);
        });

        Schema::table('user_google_identities', function (Blueprint $table) {
            $table->enum('linked_via', ['teacher_email', 'registration', 'self', 'pin_confirm', 'classroom_roster', 'google_signup'])->change();
        });
    }

    public function down(): void
    {
        // The accounts stay; their link reads as a registration link.
        DB::table('user_google_identities')->where('linked_via', 'google_signup')->update(['linked_via' => 'registration']);
        Schema::table('user_google_identities', function (Blueprint $table) {
            $table->enum('linked_via', ['teacher_email', 'registration', 'self', 'pin_confirm', 'classroom_roster'])->change();
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('teacher_google_auto_approve');
        });
    }
};
