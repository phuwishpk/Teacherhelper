<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §30.12 (assignments as posts, step 3):
 *
 * - assignments.unit_id XOR topic: the Classroom topic the courseWork is
 *   filed under; instructions: the text students read (and the courseWork
 *   description); send_to_google: post to Classroom when the assignment
 *   becomes ready; google_post_state/error: that automatic post's state;
 * - assignment_attachments: the files (class_post_files of the teacher) and
 *   links of an assignment, in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assignments', function (Blueprint $table) {
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 100)->nullable();
            $table->text('instructions')->nullable();
            $table->boolean('send_to_google')->default(true);
            $table->enum('google_post_state', ['queued', 'failed'])->nullable();
            $table->string('google_post_error', 255)->nullable();
        });

        Schema::create('assignment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assignment_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['file', 'link']);
            $table->foreignId('class_post_file_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('url', 2000)->nullable();
            $table->string('title', 200);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index('class_post_file_id', 'idx_assignment_attachment_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignment_attachments');
        Schema::table('assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('unit_id');
            $table->dropColumn(['topic', 'instructions', 'send_to_google', 'google_post_state', 'google_post_error']);
        });
    }
};
