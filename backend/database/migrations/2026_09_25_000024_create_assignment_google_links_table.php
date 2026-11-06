<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §18.4 `assignment_google_links`: the Classroom courseWork created
 * by POST /assignments/{id}/google-post. drive_file_id is the anonymous spare
 * worksheet (§18.3) uploaded to the teacher's Drive, when attached.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignment_google_links', function (Blueprint $table) {
            $table->foreignId('assignment_id')->primary()->constrained()->cascadeOnDelete();
            $table->string('course_work_id', 64);
            $table->string('alternate_link', 512);
            $table->string('drive_file_id', 128)->nullable();
            $table->foreignId('posted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('posted_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_google_links');
    }
};
