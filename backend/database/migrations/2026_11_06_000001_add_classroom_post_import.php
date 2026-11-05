<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §45 (announcements and materials pulled in from Google Classroom):
 *
 * - class_post_google_posts.origin: `krucheck` for the copy the app sent,
 *   `classroom` for a post the teacher wrote on the Classroom website and the
 *   sync pulled in; google_updated_at is that post's updateTime, so an edit
 *   is fetched again; the index serves the per-course lookups of the sync;
 * - class_post_files.source_drive_file_id: the teacher's original in Drive a
 *   pulled file was copied from (reused, never trashed; not drive_file_id);
 * - classroom_google_links.posts_synced_at: when the posts of the course were
 *   last listed (null = never: the first round pulls old posts silently).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_post_google_posts', function (Blueprint $table) {
            $table->enum('origin', ['krucheck', 'classroom'])->default('krucheck');
            $table->timestamp('google_updated_at')->nullable();
            $table->index(['course_id', 'google_id'], 'idx_cpgp_course_google');
        });

        Schema::table('class_post_files', function (Blueprint $table) {
            $table->string('source_drive_file_id', 128)->nullable();
            $table->index(['owner_id', 'source_drive_file_id'], 'idx_cpf_owner_source');
        });

        Schema::table('classroom_google_links', function (Blueprint $table) {
            $table->timestamp('posts_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('classroom_google_links', function (Blueprint $table) {
            $table->dropColumn('posts_synced_at');
        });
        Schema::table('class_post_files', function (Blueprint $table) {
            $table->dropIndex('idx_cpf_owner_source');
            $table->dropColumn('source_drive_file_id');
        });
        Schema::table('class_post_google_posts', function (Blueprint $table) {
            $table->dropIndex('idx_cpgp_course_google');
            $table->dropColumn(['origin', 'google_updated_at']);
        });
    }
};
