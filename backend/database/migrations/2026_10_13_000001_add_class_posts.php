<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * DESIGN §30.7 (class posts, step 1):
 *
 * - class_posts: an announcement or a material a teacher wrote for one
 *   classroom. A post sent to several classrooms is one row per classroom
 *   sharing a batch_id. unit_id XOR topic names its topic (both NULL = none);
 *   send_to_google is read by step 2;
 * - class_post_recipients: the students of a post with audience = students;
 * - class_post_files: an uploaded file, owned by the teacher and shared by
 *   every post that attaches it;
 * - class_post_attachments: a file or a link of a post, in order.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('classroom_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->constrained('users');
            $table->foreignId('course_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('kind', ['announcement', 'material']);
            $table->string('title', 200)->nullable();
            $table->text('body')->nullable();
            $table->foreignId('unit_id')->nullable()->constrained()->nullOnDelete();
            $table->string('topic', 100)->nullable();
            $table->enum('audience', ['all', 'students'])->default('all');
            $table->enum('state', ['draft', 'scheduled', 'published'])->default('draft');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->uuid('batch_id')->nullable();
            $table->boolean('send_to_google')->default(true);
            $table->timestamps();
            $table->index(['classroom_id', 'state', 'published_at'], 'idx_class_posts_feed');
            $table->index(['state', 'scheduled_at'], 'idx_class_posts_due');
            $table->index('batch_id', 'idx_class_posts_batch');
        });

        Schema::create('class_post_recipients', function (Blueprint $table) {
            $table->foreignId('class_post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('users')->cascadeOnDelete();
            $table->primary(['class_post_id', 'student_id']);
            $table->index('student_id', 'idx_class_post_recipient_student');
        });

        Schema::create('class_post_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users');
            $table->string('path', 255);
            $table->string('original_name', 255);
            $table->string('mime_type', 150);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->index('owner_id', 'idx_class_post_files_owner');
        });

        Schema::create('class_post_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_post_id')->constrained()->cascadeOnDelete();
            $table->enum('kind', ['file', 'link']);
            $table->foreignId('class_post_file_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('url', 2000)->nullable();
            $table->string('title', 200);
            $table->unsignedSmallInteger('position');
            $table->timestamps();
            $table->index('class_post_file_id', 'idx_class_post_attachment_file');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_post_attachments');
        Schema::dropIfExists('class_post_files');
        Schema::dropIfExists('class_post_recipients');
        Schema::dropIfExists('class_posts');
    }
};
