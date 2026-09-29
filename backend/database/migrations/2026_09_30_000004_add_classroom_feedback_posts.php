<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §19.8 part F (Phase 8 build step 6, results back to Classroom):
 *
 * - classroom_feedback_posts: one private announcement per student per
 *   publish (UNIQUE submission_id + published_at), its delivery state and
 *   Google's announcement id;
 * - google_accounts connected before the classroom.announcements scope was
 *   required are marked scope_missing (needs_reconnect, §19.7), so every
 *   query on last_error (the cron sync, the reconnect FCM) sees them. No
 *   push is sent from here: the app shows the banner on its next status.
 */
return new class extends Migration
{
    private const ANNOUNCEMENTS_SCOPE = 'https://www.googleapis.com/auth/classroom.announcements';

    public function up(): void
    {
        Schema::create('classroom_feedback_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->timestamp('published_at')->useCurrent();
            $table->string('course_id', 64);
            $table->string('google_user_id', 64);
            $table->string('announcement_id', 64)->nullable();
            $table->enum('state', ['queued', 'posted', 'failed'])->default('queued');
            $table->string('last_error')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->unique(['submission_id', 'published_at'], 'uq_feedback_post');
        });

        $this->markAccountsWithoutAnnouncementsScope();
    }

    /** @return int the accounts marked (public for the test) */
    public function markAccountsWithoutAnnouncementsScope(): int
    {
        return DB::table('google_accounts')
            ->whereNull('last_error')
            ->where('scopes', 'not like', '%'.self::ANNOUNCEMENTS_SCOPE.'%')
            ->update(['last_error' => 'scope_missing']);
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_feedback_posts');
    }
};
