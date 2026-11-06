<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * DESIGN §29.13.1 (step 3ก): one admin level. Admins of a school become
 * system admins; the `schools` rows stay as the teachers' workspaces, so no
 * column changes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->where('role', 'admin')->whereNotNull('school_id')->update(['school_id' => null]);
    }

    public function down(): void
    {
        // The old school of an admin is not kept; nothing to restore.
    }
};
