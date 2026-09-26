<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4: where a scan came from. `classroom` scans were downloaded
 * from a Google Classroom submission on the teacher's phone and carry its id
 * (classroom_submission_imports.google_submission_id).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->enum('source', ['camera', 'classroom'])->default('camera');
            $table->string('google_submission_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('scans', function (Blueprint $table) {
            $table->dropColumn(['source', 'google_submission_id']);
        });
    }
};
