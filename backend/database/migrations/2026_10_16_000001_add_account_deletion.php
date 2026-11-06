<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §31: an admin deletes a teacher or student account. The account is
 * blocked at once and restorable for 30 days (users.deleted_at); a deleted
 * teacher's own classrooms are marked with the same moment
 * (classrooms.deleted_at) so a restore brings exactly those back.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->index('idx_users_deleted');
            // No foreign key: SQLite could only drop it again by rebuilding `users`,
            // and audit_logs (account.delete) already ties the admin to the row.
            $table->unsignedBigInteger('deleted_by')->nullable();
        });
        Schema::table('classrooms', function (Blueprint $table) {
            $table->timestamp('deleted_at')->nullable()->index('idx_classrooms_deleted');
        });
    }

    public function down(): void
    {
        Schema::table('classrooms', function (Blueprint $table) {
            $table->dropIndex('idx_classrooms_deleted');
            $table->dropColumn('deleted_at');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('idx_users_deleted');
            $table->dropColumn(['deleted_at', 'deleted_by']);
        });
    }
};
