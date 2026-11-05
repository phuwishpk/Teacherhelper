<?php

namespace App\Domain\Google;

use App\Domain\ClassPosts\AttachmentInput;
use App\Domain\ClassPosts\ClassPostFileTypes;
use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Firestore\LiveClassroom;
use App\Domain\Notifications\Notifier;
use App\Jobs\ImportClassroomPostJob;
use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use App\Models\ClassPostGooglePost;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomGoogleTopic;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\GoogleAccount;
use App\Models\Unit;
use App\Models\User;
use finfo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Announcements and materials the teacher writes on the Classroom website
 * become class posts of the linked classroom and subject (DESIGN §45):
 *
 * - scan() is one course's step of a sync round: it lists both kinds,
 *   queues an ImportClassroomPostJob for each new or edited one (oldest
 *   first, at most max_post_imports), skips the copies the app sent itself
 *   and deletes the posts whose original is gone;
 * - import() is the job's work: PDFs, pictures and Office files are copied
 *   from the teacher's Drive (outside the transaction), everything else is
 *   a link; the post is written under a lock of the link row, so two jobs
 *   never make the same post twice; a new announcement written after the
 *   previous round notifies the students (old posts come in silently).
 *
 * Pulled posts are read-only in the app: Classroom only lets the project
 * that created an item change it.
 */
final class ClassroomPostImporter
{
    public const KINDS = [ClassPost::KIND_ANNOUNCEMENT, ClassPost::KIND_MATERIAL];

    private const MAX_BODY = 10000;

    private const MAX_TITLE = 200;

    private const MAX_TOPIC = 100;

    public function __construct(
        private readonly GoogleAccessTokens $tokens,
        private readonly Notifier $notifier,
    ) {}

    /**
     * Lists the course's posts, queues the new or edited ones and deletes
     * the ones gone from Classroom.
     *
     * @return int posts queued
     *
     * @throws GoogleApiException
     */
    public static function scan(GoogleApi $api, ClassroomGoogleLink $link): int
    {
        $startedAt = now();
        $listed = [];
        $announcements = $api->announcements($link->course_id);
        foreach ($announcements as $item) {
            $listed[] = [ClassPost::KIND_ANNOUNCEMENT, $item];
        }
        $materials = $api->courseWorkMaterials($link->course_id);
        foreach ($materials as $item) {
            $listed[] = [ClassPost::KIND_MATERIAL, $item];
        }
        $complete = ! GoogleApi::maybeTruncated(count($announcements)) && ! GoogleApi::maybeTruncated(count($materials));

        $ours = ClassPostGooglePost::query()
            ->where('course_id', $link->course_id)
            ->where('origin', ClassPostGooglePost::ORIGIN_KRUCHECK)
            ->whereNotNull('google_id')
            ->get(['kind', 'google_id'])
            ->mapWithKeys(fn (ClassPostGooglePost $r) => [$r->kind.':'.$r->google_id => true]);
        $pulled = self::pulledRows($link)->keyBy(fn (ClassPostGooglePost $r) => $r->kind.':'.$r->google_id);

        $seen = [];
        $due = [];
        foreach ($listed as [$kind, $item]) {
            $id = self::id($item);
            if ($id === null || ($item['state'] ?? 'PUBLISHED') !== 'PUBLISHED') {
                continue;
            }
            $key = $kind.':'.$id;
            $seen[$key] = true;
            if (isset($ours[$key])) {
                continue; // the app's own copy (DESIGN §30.4)
            }
            $row = $pulled->get($key);
            $updated = self::time($item['updateTime'] ?? null);
            if ($row !== null && $row->google_updated_at !== null && ($updated === null || $updated->lte($row->google_updated_at))) {
                continue; // unchanged
            }
            $due[] = ['kind' => $kind, 'item' => $item, 'at' => self::publishedAt($item) ?? $startedAt];
        }
        usort($due, fn (array $a, array $b) => $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
        $due = array_slice($due, 0, (int) config('eduvision.classroom_sync.max_post_imports'));

        $notifyAfter = $link->posts_synced_at?->toIso8601String();
        foreach ($due as $d) {
            ImportClassroomPostJob::dispatch((int) $link->id, $d['kind'], $d['item'], $notifyAfter);
        }

        if ($complete) {
            foreach ($pulled as $key => $row) {
                if (! isset($seen[$key])) {
                    self::remove((int) $row->class_post_id);
                }
            }
        }

        $link->posts_synced_at = $startedAt;
        $link->save();

        return count($due);
    }

    /**
     * The job's work: create or update the post of one Classroom item.
     *
     * @param  array<string, mixed>  $item  the Announcement or CourseWorkMaterial resource
     * @param  string|null  $notifyAfter  when the previous round listed the course (null: the first round, nobody is told)
     *
     * @throws GoogleApiException only transient ones (the queue tries again)
     */
    public function import(int $linkId, string $kind, array $item, ?string $notifyAfter): ?ClassPost
    {
        $link = ClassroomGoogleLink::query()->find($linkId);
        $id = self::id($item);
        if ($link === null || $id === null || ! in_array($kind, self::KINDS, true)) {
            return null;
        }
        $classroom = Classroom::query()->find($link->classroom_id);
        $account = GoogleAccount::query()->find($link->owner_user_id);
        $owner = User::query()->find($link->owner_user_id);
        if ($classroom === null || $classroom->closed_at !== null || $account === null || $account->needsReconnect() || $owner === null) {
            return null;
        }
        $updated = self::time($item['updateTime'] ?? null);
        $known = self::pulledRows($link)->first(fn (ClassPostGooglePost $r) => $r->kind === $kind && $r->google_id === $id);
        if ($known !== null && $known->google_updated_at !== null && $updated !== null && $updated->lte($known->google_updated_at)) {
            return $known->post; // another job did it
        }

        $api = GoogleApi::forAccount($account, $this->tokens);
        $course = CourseWorkImporter::courseOf($link);
        $attachments = $this->attachments($api, $owner, $item['materials'] ?? null);
        [$unitId, $topic] = $kind === ClassPost::KIND_MATERIAL ? $this->topic($api, $link, $course, $item['topicId'] ?? null) : [null, null];
        [$audience, $studentIds] = self::audience($link, $item);
        $publishedAt = self::publishedAt($item) ?? now();
        [$title, $body] = self::content($kind, $item);

        [$post, $created, $removed] = DB::transaction(function () use ($link, $kind, $id, $item, $owner, $course, $attachments, $unitId, $topic, $audience, $studentIds, $publishedAt, $title, $body, $updated) {
            ClassroomGoogleLink::query()->whereKey($link->id)->lockForUpdate()->first();
            $ours = ClassPostGooglePost::query()
                ->where('course_id', $link->course_id)->where('kind', $kind)->where('google_id', $id)
                ->where('origin', ClassPostGooglePost::ORIGIN_KRUCHECK)->exists();
            if ($ours) {
                return [null, false, []];
            }
            $row = self::pulledRows($link)->first(fn (ClassPostGooglePost $r) => $r->kind === $kind && $r->google_id === $id);
            $post = $row?->post ?? new ClassPost;
            $removed = $row === null ? [] : AttachmentInput::fileIds($post->attachments()->get());
            $post->fill([
                'classroom_id' => $link->classroom_id,
                'author_id' => $owner->id,
                'course_id' => $course?->id,
                'kind' => $kind,
                'title' => $title,
                'body' => $body,
                'unit_id' => $unitId,
                'topic' => $topic,
                'audience' => $audience,
                'state' => ClassPost::STATE_PUBLISHED,
                'scheduled_at' => null,
                'published_at' => $publishedAt,
                'batch_id' => null,
                'send_to_google' => false,
            ])->save();
            $post->attachments()->delete();
            AttachmentInput::write($post->attachments(), $attachments);
            $post->recipients()->sync($audience === ClassPost::AUDIENCE_STUDENTS ? $studentIds : []);
            ClassPostGooglePost::query()->updateOrCreate(['class_post_id' => $post->id], [
                'course_id' => $link->course_id,
                'kind' => $kind,
                'google_id' => mb_substr($id, 0, 64),
                'alternate_link' => self::alternateLink($item),
                'state' => ClassPostGooglePost::STATE_SENT,
                'last_error' => null,
                'sent_at' => now(),
                'origin' => ClassPostGooglePost::ORIGIN_CLASSROOM,
                'google_updated_at' => $updated ?? now(),
            ]);

            return [$post, $row === null, $removed];
        });

        $copied = array_values(array_filter(array_map(fn (array $a) => $a['file']?->id, $attachments)));
        if ($post === null) {
            ClassPosts::deleteUnused($copied);

            return null;
        }
        ClassPosts::deleteUnused($removed);
        LiveClassroom::feedChanged((int) $post->classroom_id);
        Log::info('google.class_post_pulled', ['post_id' => $post->id, 'kind' => $kind, 'created' => $created]);

        if ($created && $kind === ClassPost::KIND_ANNOUNCEMENT && $notifyAfter !== null && $publishedAt->gt(Carbon::parse($notifyAfter))) {
            try {
                $this->notifier->classPostPublished($post->load('classroom'));
            } catch (Throwable $e) {
                report($e); // a push outage must not repeat the import
            }
        }

        return $post;
    }

    /** Deletes a pulled post whose original is gone from Classroom, with the files no other post uses. */
    public static function remove(int $postId): void
    {
        DB::transaction(function () use ($postId) {
            $post = ClassPost::query()->lockForUpdate()->find($postId);
            if ($post === null) {
                return;
            }
            $fileIds = AttachmentInput::fileIds($post->attachments()->get());
            $post->delete(); // attachments, recipients and the google row cascade
            LiveClassroom::feedChanged((int) $post->classroom_id);
            ClassPosts::deleteUnused($fileIds);
        });
    }

    /**
     * The pulled posts of the link's course in the link's classroom (posts
     * pulled for another classroom the course was linked to before stay
     * there and are not followed any more).
     *
     * @return Collection<int, ClassPostGooglePost>
     */
    private static function pulledRows(ClassroomGoogleLink $link): Collection
    {
        return ClassPostGooglePost::query()
            ->with('post')
            ->where('course_id', $link->course_id)
            ->where('origin', ClassPostGooglePost::ORIGIN_CLASSROOM)
            ->whereIn('class_post_id', ClassPost::query()->where('classroom_id', $link->classroom_id)->select('id'))
            ->get();
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{0: string|null, 1: string|null} title, body
     */
    private static function content(string $kind, array $item): array
    {
        if ($kind === ClassPost::KIND_ANNOUNCEMENT) {
            return [null, self::text($item['text'] ?? null, self::MAX_BODY)];
        }

        return [
            self::text(preg_replace('/\s+/u', ' ', (string) ($item['title'] ?? '')), self::MAX_TITLE) ?? 'เอกสารประกอบ',
            self::text($item['description'] ?? null, self::MAX_BODY),
        ];
    }

    /**
     * Everyone, or the chosen students who are paired with Google.
     *
     * @param  array<string, mixed>  $item
     * @return array{0: string, 1: list<int>}
     */
    private static function audience(ClassroomGoogleLink $link, array $item): array
    {
        if (($item['assigneeMode'] ?? 'ALL_STUDENTS') !== 'INDIVIDUAL_STUDENTS') {
            return [ClassPost::AUDIENCE_ALL, []];
        }
        $googleIds = array_values(array_filter((array) ($item['individualStudentsOptions']['studentIds'] ?? []), 'is_string'));
        $students = $googleIds === [] ? [] : ClassroomStudent::query()
            ->where('classroom_id', $link->classroom_id)
            ->whereIn('google_user_id', $googleIds)
            ->orderBy('student_id')
            ->pluck('student_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [ClassPost::AUDIENCE_STUDENTS, $students];
    }

    /**
     * The unit of the subject the topic stands for, else the topic's name.
     *
     * @return array{0: int|null, 1: string|null}
     *
     * @throws GoogleApiException when Google is unreachable
     */
    private function topic(GoogleApi $api, ClassroomGoogleLink $link, ?Course $course, mixed $topicId): array
    {
        if (! is_string($topicId) || $topicId === '') {
            return [null, null];
        }
        $known = ClassroomGoogleTopic::query()->where('course_id', $link->course_id)->where('topic_id', $topicId)->first();
        if ($known === null) {
            try {
                $topics = $api->topics($link->course_id);
            } catch (GoogleApiException $e) {
                if ($e->isTransient()) {
                    throw $e;
                }

                return [null, null];
            }
            foreach ($topics as $t) {
                $name = trim($t['name']);
                if ($t['id'] === $topicId && $name !== '') {
                    $known = ClassroomGoogleTopic::query()->firstOrCreate(
                        ['course_id' => $link->course_id, 'topic_id' => mb_substr($topicId, 0, 64)],
                        ['name' => mb_substr($name, 0, self::MAX_TOPIC)],
                    );
                }
            }
            if ($known === null) {
                return [null, null];
            }
        }
        if ($known->unit_id !== null && $course !== null && Unit::query()->whereKey($known->unit_id)->where('course_id', $course->id)->exists()) {
            return [(int) $known->unit_id, null];
        }

        return [null, mb_substr((string) $known->name, 0, self::MAX_TOPIC)];
    }

    /**
     * The attachment rows: Drive files copied when they can be, links for
     * the rest. Materials that are neither are left out.
     *
     * @return list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>
     *
     * @throws GoogleApiException when Google is unreachable
     */
    private function attachments(GoogleApi $api, User $owner, mixed $materials): array
    {
        $rows = [];
        foreach (is_array($materials) ? $materials : [] as $material) {
            if (! is_array($material)) {
                continue;
            }
            $row = match (true) {
                is_array($material['driveFile']['driveFile'] ?? null) => $this->driveFile($api, $owner, $material['driveFile']['driveFile']),
                is_array($material['link'] ?? null) => self::link($material['link']['url'] ?? null, $material['link']['title'] ?? null),
                is_array($material['youtubeVideo'] ?? null) => self::link(
                    $material['youtubeVideo']['alternateLink'] ?? (is_string($material['youtubeVideo']['id'] ?? null) ? 'https://www.youtube.com/watch?v='.rawurlencode($material['youtubeVideo']['id']) : null),
                    $material['youtubeVideo']['title'] ?? null,
                ),
                is_array($material['form'] ?? null) => self::link($material['form']['formUrl'] ?? null, $material['form']['title'] ?? null),
                default => null,
            };
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $file  the material's DriveFile
     * @return array{kind: string, file: ClassPostFile|null, url: string|null, title: string}|null
     *
     * @throws GoogleApiException when Google is unreachable
     */
    private function driveFile(GoogleApi $api, User $owner, array $file): ?array
    {
        $id = is_string($file['id'] ?? null) && $file['id'] !== '' ? $file['id'] : null;
        if ($id === null) {
            return null;
        }
        $title = is_string($file['title'] ?? null) && trim($file['title']) !== '' ? trim($file['title']) : 'ไฟล์จาก Google Drive';
        $copy = ClassPostFile::query()->where('owner_id', $owner->id)->where('source_drive_file_id', $id)->orderBy('id')->first();
        if ($copy === null || ! Storage::disk('local')->exists($copy->path)) {
            $copy = $this->copy($api, $owner, $id, $title);
        }
        if ($copy !== null) {
            return ['kind' => ClassPostAttachment::KIND_FILE, 'file' => $copy, 'url' => null, 'title' => mb_substr($title, 0, self::MAX_TITLE)];
        }
        $url = is_string($file['alternateLink'] ?? null) && $file['alternateLink'] !== ''
            ? $file['alternateLink']
            : 'https://drive.google.com/file/d/'.rawurlencode($id).'/view';

        return self::link($url, $title);
    }

    /**
     * Copies a Drive file into the owner's class post files when it is a
     * type a post may carry, within the size limit and the owner's space.
     *
     * @throws GoogleApiException when Google is unreachable
     */
    private function copy(GoogleApi $api, User $owner, string $id, string $title): ?ClassPostFile
    {
        $maxBytes = (int) config('eduvision.class_posts.max_file_mb') * 1024 * 1024;
        try {
            $meta = $api->driveFile($id);
            if (! ClassPostFileTypes::mayBe($meta['mime_type']) || $meta['size'] === null || $meta['size'] <= 0 || $meta['size'] > $maxBytes) {
                return null;
            }
            $usage = ClassPosts::usage($owner);
            if ($usage['limit_bytes'] < $usage['used_bytes'] + $meta['size']) {
                return null;
            }
            $bytes = $api->downloadDriveFile($id);
        } catch (GoogleApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            Log::info('google.class_post_file_not_copied', ['kind' => $e->kind]);

            return null;
        }
        $size = strlen($bytes);
        if ($size === 0 || $size > $maxBytes) {
            return null;
        }
        $name = $meta['name'] !== '' ? $meta['name'] : $title;
        $type = ClassPostFileTypes::of((string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes), $name);
        if ($type === null) {
            return null;
        }
        [$mime, $extension] = $type;
        $path = "class-posts/{$owner->id}/".Str::uuid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return ClassPostFile::query()->create([
            'owner_id' => $owner->id,
            'path' => $path,
            'original_name' => ClassPostFileTypes::name($name, $extension),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'sha256' => hash('sha256', $bytes),
            'source_drive_file_id' => mb_substr($id, 0, 128),
        ]);
    }

    /** @return array{kind: string, file: null, url: string, title: string}|null an http(s) link */
    private static function link(mixed $url, mixed $title): ?array
    {
        if (! is_string($url)) {
            return null;
        }
        $url = trim($url);
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if (! in_array($scheme, ['http', 'https'], true) || (string) parse_url($url, PHP_URL_HOST) === '' || mb_strlen($url) > 2000) {
            return null;
        }
        $title = is_string($title) ? trim((string) preg_replace('/\s+/u', ' ', $title)) : '';

        return ['kind' => ClassPostAttachment::KIND_LINK, 'file' => null, 'url' => $url, 'title' => mb_substr($title !== '' ? $title : $url, 0, self::MAX_TITLE)];
    }

    /** @param  array<string, mixed>  $item */
    private static function id(array $item): ?string
    {
        $id = $item['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /** @param  array<string, mixed>  $item */
    private static function alternateLink(array $item): ?string
    {
        $link = $item['alternateLink'] ?? null;

        return is_string($link) && $link !== '' ? mb_substr($link, 0, 512) : null;
    }

    /**
     * When the post appeared in Classroom: its scheduled time, else its creation.
     *
     * @param  array<string, mixed>  $item
     */
    private static function publishedAt(array $item): ?Carbon
    {
        return self::time($item['scheduledTime'] ?? null) ?? self::time($item['creationTime'] ?? null);
    }

    private static function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }

    private static function text(mixed $value, int $max): ?string
    {
        $text = trim(str_replace("\r\n", "\n", (string) ($value ?? '')));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
