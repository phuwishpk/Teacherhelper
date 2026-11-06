<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4: the Google Classroom account matched to each student of a
 * linked classroom (PUT /classrooms/{id}/google-roster). One student per
 * Google user in a classroom; NULLs do not collide in the unique key.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->string('google_user_id', 64)->nullable();
            $table->string('google_email')->nullable();
            $table->unique(['classroom_id', 'google_user_id'], 'uq_class_google_user');
        });
    }

    public function down(): void
    {
        Schema::table('classroom_students', function (Blueprint $table) {
            $table->dropUnique('uq_class_google_user');
            $table->dropColumn(['google_user_id', 'google_email']);
        });
    }
};
