<?php

use App\Domain\Google\GoogleScopes;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §30.11 (sending class posts to Google Classroom, step 2):
 *
 * - class_post_google_posts: the Classroom copy of a post (one per post):
 *   the announcement or courseWorkMaterial id, its link and the send state;
 * - class_post_files.drive_file_id: the copy of an uploaded file in the
 *   owner's Drive, made on the first send and shared by every post of theirs;
 * - classroom_google_topics: the topics the app created or found in a
 *   course, by unit (renamed with the unit) or by name;
 * - accounts connected before the materials and topics scopes existed are
 *   marked scope_missing so the teacher connects again (the §19.7 precedent).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_post_google_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_post_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('course_id', 64);
            $table->enum('kind', ['announcement', 'material']);
            $table->string('google_id', 64)->nullable();
            $table->string('alternate_link', 512)->nullable();
            $table->enum('state', ['queued', 'sent', 'failed'])->default('queued');
            $table->string('last_error', 255)->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::table('class_post_files', function (Blueprint $table) {
            $table->string('drive_file_id', 64)->nullable();
        });

        Schema::create('classroom_google_topics', function (Blueprint $table) {
            $table->id();
            $table->string('course_id', 64);
            $table->string('topic_id', 64);
            $table->string('name', 100);
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['course_id', 'topic_id'], 'uq_google_topic');
            $table->index(['course_id', 'unit_id'], 'idx_google_topic_unit');
        });

        $this->markAccountsWithoutClassPostScopes();
    }

    /** @return int the accounts marked (public for the test) */
    public function markAccountsWithoutClassPostScopes(): int
    {
        return DB::table('google_accounts')
            ->whereNull('last_error')
            ->where(fn ($q) => $q
                ->where('scopes', 'not like', '%'.GoogleScopes::MATERIALS.'%')
                ->orWhere('scopes', 'not like', '%'.GoogleScopes::TOPICS.'%'))
            ->update(['last_error' => 'scope_missing']);
    }

    public function down(): void
    {
        Schema::dropIfExists('classroom_google_topics');
        Schema::table('class_post_files', function (Blueprint $table) {
            $table->dropColumn('drive_file_id');
        });
        Schema::dropIfExists('class_post_google_posts');
    }
};
