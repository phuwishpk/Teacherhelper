<?php

namespace App\Domain\ClassPosts;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Students\StudentClassrooms;
use App\Events\ClassPostPublished;
use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Class posts (DESIGN §30): who sees a post, how it is shown, publishing
 * the scheduled ones and removing files nobody uses any more.
 */
final class ClassPosts
{
    public const RELATIONS = ['classroom:id,name,academic_year,closed_at', 'author:id,name', 'course:id,code,name', 'unit:id,title', 'attachments.file'];

    /**
     * Posts of a classroom as one of its teachers sees them: everything
     * published, plus the teacher's own drafts and scheduled posts.
     *
     * @return Builder<ClassPost>
     */
    public static function forTeacher(User $teacher): Builder
    {
        return ClassPost::query()
            ->whereIn('class_posts.classroom_id', ClassroomAccess::classrooms($teacher)->select('classrooms.id'))
            ->where(fn (Builder $q) => $q
                ->where('class_posts.state', ClassPost::STATE_PUBLISHED)
                ->orWhere('class_posts.author_id', $teacher->id));
    }

    /**
     * Published posts the student receives: in a classroom of theirs, for
     * everyone or naming them.
     *
     * @return Builder<ClassPost>
     */
    public static function forStudent(User $student): Builder
    {
        return ClassPost::query()
            ->where('class_posts.state', ClassPost::STATE_PUBLISHED)
            ->whereIn('class_posts.classroom_id', DB::table('classroom_students')->select('classroom_id')->where('student_id', $student->id))
            ->where(fn (Builder $q) => $q
                ->where('class_posts.audience', ClassPost::AUDIENCE_ALL)
                ->orWhereIn('class_posts.id', DB::table('class_post_recipients')->select('class_post_id')->where('student_id', $student->id)));
    }

    /**
     * The students a published post reaches (for the push notification).
     *
     * @return list<int>
     */
    public static function recipientIds(ClassPost $post): array
    {
        $roster = DB::table('classroom_students')->where('classroom_id', $post->classroom_id);
        if ($post->audience === ClassPost::AUDIENCE_STUDENTS) {
            $roster->whereIn('student_id', DB::table('class_post_recipients')->select('student_id')->where('class_post_id', $post->id));
        }

        return $roster->orderBy('student_id')->pluck('student_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * One post for the API. A teacher also gets the recipients, the batch
     * and the Google switch; a student never sees drafts, so no state.
     *
     * @return array<string, mixed>
     */
    public static function payload(ClassPost $post, User $viewer): array
    {
        $teacher = $viewer->role === User::ROLE_TEACHER;
        $data = [
            'id' => $post->id,
            'kind' => $post->kind,
            'title' => $post->title,
            'body' => $post->body,
            'classroom' => StudentClassrooms::label($post->classroom),
            'course' => StudentClassrooms::course($post->course),
            'unit' => $post->unit === null ? null : ['id' => $post->unit->id, 'title' => (string) $post->unit->title],
            'topic' => $post->unit !== null ? (string) $post->unit->title : $post->topic,
            'author' => ['id' => $post->author_id, 'name' => (string) $post->author?->name],
            'audience' => $post->audience,
            'published_at' => $post->published_at?->toIso8601String(),
            'attachments' => $post->attachments->map(fn (ClassPostAttachment $a) => self::attachment($a))->values()->all(),
        ];
        if (! $teacher) {
            return $data;
        }

        return $data + [
            'state' => $post->state,
            'scheduled_at' => $post->scheduled_at?->toIso8601String(),
            'student_ids' => $post->audience === ClassPost::AUDIENCE_STUDENTS
                ? DB::table('class_post_recipients')->where('class_post_id', $post->id)->orderBy('student_id')->pluck('student_id')->map(fn ($id) => (int) $id)->all()
                : [],
            'batch_id' => $post->batch_id,
            'send_to_google' => (bool) $post->send_to_google,
            'is_mine' => (int) $post->author_id === (int) $viewer->id,
            'updated_at' => $post->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function attachment(ClassPostAttachment $attachment): array
    {
        $file = $attachment->file;

        return [
            'id' => $attachment->id,
            'kind' => $attachment->kind,
            'title' => (string) $attachment->title,
            'url' => $attachment->url,
            'file' => $file === null ? null : self::file($file),
        ];
    }

    /**
     * @return array{id: int, name: string, mime_type: string, size_bytes: int, download_url: string}
     */
    public static function file(ClassPostFile $file): array
    {
        return [
            'id' => $file->id,
            'name' => (string) $file->original_name,
            'mime_type' => (string) $file->mime_type,
            'size_bytes' => (int) $file->size_bytes,
            'download_url' => "/api/v1/class-post-files/{$file->id}/file",
        ];
    }

    /**
     * @return array{used_bytes: int, limit_bytes: int}
     */
    public static function usage(User $teacher): array
    {
        return [
            'used_bytes' => (int) ClassPostFile::query()->where('owner_id', $teacher->id)->sum('size_bytes'),
            'limit_bytes' => (int) config('eduvision.class_posts.quota_mb') * 1024 * 1024,
        ];
    }

    /** Marks a draft or scheduled post published now and announces it. */
    public static function publish(ClassPost $post): void
    {
        if ($post->isPublished()) {
            return;
        }
        $post->forceFill(['state' => ClassPost::STATE_PUBLISHED, 'published_at' => now(), 'scheduled_at' => null])->save();
        ClassPostPublished::dispatch($post->id);
    }

    /**
     * The cron step (every minute): publishes the scheduled posts whose time
     * has come, oldest first. Returns how many.
     */
    public static function publishDue(int $limit = 100): int
    {
        $ids = ClassPost::query()
            ->where('state', ClassPost::STATE_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        $published = 0;
        foreach ($ids as $id) {
            $published += DB::transaction(function () use ($id) {
                $post = ClassPost::query()->lockForUpdate()->find($id);
                if ($post === null || $post->state !== ClassPost::STATE_SCHEDULED) {
                    return 0;
                }
                self::publish($post);

                return 1;
            });
        }

        return $published;
    }

    /**
     * Deletes the given files that no post attaches any more (rows and
     * bytes). Call after removing attachments.
     *
     * @param  list<int>  $fileIds
     */
    public static function deleteUnused(array $fileIds): void
    {
        if ($fileIds === []) {
            return;
        }
        $files = ClassPostFile::query()->whereIn('id', $fileIds)->whereDoesntHave('attachments')->get();
        foreach ($files as $file) {
            self::deleteFile($file);
        }
    }

    /**
     * The cron step: uploaded files nobody attached within orphan_hours.
     * Returns how many were deleted.
     */
    public static function purgeOrphans(int $limit = 200): int
    {
        $files = ClassPostFile::query()
            ->whereDoesntHave('attachments')
            ->where('created_at', '<', now()->subHours((int) config('eduvision.class_posts.orphan_hours')))
            ->orderBy('id')
            ->limit($limit)
            ->get();
        foreach ($files as $file) {
            self::deleteFile($file);
        }

        return $files->count();
    }

    public static function deleteFile(ClassPostFile $file): void
    {
        $path = $file->path;
        $file->delete();
        Storage::disk('local')->delete($path);
    }
}
