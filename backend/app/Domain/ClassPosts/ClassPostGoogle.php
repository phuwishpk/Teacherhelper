<?php

namespace App\Domain\ClassPosts;

use App\Domain\Google\ClassroomMaterials;
use App\Domain\Google\ClassroomTopics;
use App\Domain\Google\GoogleAccessTokens;
use App\Domain\Google\GoogleApi;
use App\Domain\Google\GoogleApiException;
use App\Domain\Google\GoogleErrors;
use App\Jobs\DeleteClassPostGoogleJob;
use App\Jobs\PushClassPostGoogleJob;
use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use App\Models\ClassPostGooglePost;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\GoogleAccount;
use Illuminate\Support\Facades\Log;

/**
 * The Google Classroom copy of a class post (DESIGN §30.4): an announcement
 * or a courseWorkMaterial in the course the post's author linked to the
 * classroom, made from the queue after the post is published, patched
 * after an edit and deleted with the post. Files go up to the author's
 * Drive once; materials sit under the topic of their unit.
 */
final class ClassPostGoogle
{
    public const SENT = 'sent';

    public const SKIPPED = 'skipped';

    public const FAILED = 'failed';

    public const STATE_NOT_SENT = 'not_sent';

    public function __construct(private readonly GoogleAccessTokens $tokens) {}

    /**
     * Queues the copy of a published post whose author linked the
     * classroom (a first send, or a patch after an edit). Null when nothing
     * is to be sent.
     */
    public function queue(ClassPost $post): ?ClassPostGooglePost
    {
        if (! $post->isPublished() || ! $post->send_to_google) {
            return null;
        }
        $link = ClassroomGoogleLink::of((int) $post->classroom_id, (int) $post->author_id);
        if ($link === null) {
            return null;
        }
        $row = ClassPostGooglePost::query()->firstOrNew(['class_post_id' => $post->id]);
        if ($row->exists && $row->isInClassroom() && $row->course_id !== $link->course_id) {
            // Relinked to another course since: the old copy is deleted there.
            DeleteClassPostGoogleJob::dispatch((int) $post->author_id, $row->course_id, $row->kind, (string) $row->google_id);
            $row->google_id = null;
            $row->alternate_link = null;
        }
        $row->fill([
            'course_id' => $link->course_id,
            'kind' => $post->kind,
            'state' => ClassPostGooglePost::STATE_QUEUED,
            'last_error' => null,
        ])->save();
        PushClassPostGoogleJob::dispatch($row->id);

        return $row;
    }

    /** Removes the Classroom copy (the post was deleted, or "ส่งไป Classroom" was switched off). */
    public function unsend(ClassPost $post): void
    {
        $row = $post->googlePost()->first();
        if ($row === null) {
            return;
        }
        if ($row->isInClassroom()) {
            DeleteClassPostGoogleJob::dispatch((int) $post->author_id, $row->course_id, $row->kind, (string) $row->google_id);
        }
        $row->delete();
    }

    /** The author's "ส่งอีกครั้ง": a failed copy goes back to the queue. */
    public function retry(ClassPost $post): ?ClassPostGooglePost
    {
        return $this->queue($post);
    }

    /**
     * The job's work: create or patch the copy.
     *
     * @return self::SENT|self::SKIPPED|self::FAILED
     *
     * @throws GoogleApiException only transient ones (unavailable)
     */
    public function push(int $rowId): string
    {
        $row = ClassPostGooglePost::query()->find($rowId);
        if ($row === null || $row->state === ClassPostGooglePost::STATE_SENT) {
            return self::SKIPPED;
        }
        $post = ClassPost::query()->with(['attachments.file', 'unit'])->find($row->class_post_id);
        if ($post === null) {
            $row->delete();

            return self::SKIPPED;
        }
        if (! $post->isPublished() || ! $post->send_to_google) {
            return self::SKIPPED;
        }
        $link = ClassroomGoogleLink::of((int) $post->classroom_id, (int) $post->author_id);
        if ($link === null) {
            return $this->fail($row, 'ห้องเรียนนี้ไม่ได้ผูกกับ Google Classroom แล้ว ผูกคอร์สแล้วกดส่งอีกครั้ง');
        }
        if ($row->course_id !== $link->course_id) {
            if ($row->isInClassroom()) {
                DeleteClassPostGoogleJob::dispatch((int) $post->author_id, $row->course_id, $row->kind, (string) $row->google_id);
            }
            $row->google_id = null;
            $row->alternate_link = null;
            $row->course_id = $link->course_id;
        }
        $account = GoogleAccount::query()->find($post->author_id);
        if ($account === null) {
            return $this->fail($row, 'บัญชี Google ของคุณไม่ได้เชื่อมอยู่ เชื่อมใน ตั้งค่า → Google Classroom แล้วกดส่งอีกครั้ง');
        }
        if ($account->needsReconnect()) {
            return $this->fail($row, $account->reconnectMessage() ?? GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE);
        }

        $studentIds = null;
        if ($post->audience === ClassPost::AUDIENCE_STUDENTS) {
            $studentIds = ClassroomStudent::query()
                ->where('classroom_id', $post->classroom_id)
                ->whereIn('student_id', $post->recipients()->select('users.id'))
                ->whereNotNull('google_user_id')
                ->orderBy('google_user_id')
                ->pluck('google_user_id')
                ->map(fn ($id) => (string) $id)
                ->all();
            if ($studentIds === []) {
                return $this->fail($row, 'นักเรียนที่เลือกยังไม่มีใครจับคู่กับบัญชี Google จับคู่ในหน้ารายชื่อ Google ของห้องแล้วกดส่งอีกครั้ง');
            }
        }

        $api = GoogleApi::forAccount($account, $this->tokens);
        $topicId = null;
        try {
            $materials = ClassroomMaterials::of($api, $post->attachments);
            if ($materials === null) {
                return $this->fail($row, 'ไฟล์แนบของโพสต์หายไปจากเซิร์ฟเวอร์ ลบโพสต์แล้วสร้างใหม่');
            }
            if ($post->kind === ClassPost::KIND_MATERIAL) {
                $topicName = $post->unit?->title ?? $post->topic;
                if ($topicName !== null && trim($topicName) !== '') {
                    $topicId = ClassroomTopics::idFor($api, $row->course_id, $topicName, $post->unit_id);
                }
            }
            if ($row->isInClassroom()) {
                $this->patch($api, $row, $post, $topicId);
            } else {
                $created = $post->kind === ClassPost::KIND_ANNOUNCEMENT
                    ? $api->createAnnouncement($row->course_id, self::announcementBody($post, $materials, $studentIds))
                    : $api->createCourseWorkMaterial($row->course_id, self::materialBody($post, $materials, $topicId, $studentIds));
                $row->google_id = $created['id'] !== '' ? mb_substr($created['id'], 0, 64) : null;
                $row->alternate_link = $created['alternate_link'] !== '' ? mb_substr($created['alternate_link'], 0, 512) : null;
            }
        } catch (GoogleApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            if ($e->kind === GoogleApiException::NOT_FOUND && $topicId !== null) {
                ClassroomTopics::forget($row->course_id, $topicId);
            }
            Log::warning('google.class_post_failed', ['post_id' => $post->id, 'kind' => $e->kind, 'message' => $e->getMessage()]);

            return $this->fail($row, self::errorText($e));
        }

        $row->state = ClassPostGooglePost::STATE_SENT;
        $row->sent_at = now();
        $row->last_error = null;
        $row->save();
        Log::info('google.class_post_sent', ['post_id' => $post->id, 'google_id' => $row->google_id]);

        return self::SENT;
    }

    /** Records a failure the queue gave up on (PushClassPostGoogleJob after its last attempt). */
    public function markFailed(int $rowId, string $reason): void
    {
        $row = ClassPostGooglePost::query()->find($rowId);
        if ($row !== null && $row->state !== ClassPostGooglePost::STATE_SENT) {
            $this->fail($row, $reason);
        }
    }

    /**
     * The `google` field of a teacher's post payload (DESIGN §30.8).
     * $linked says whether the author linked the classroom (a list computes
     * it once for all its posts); null looks it up.
     *
     * @return array{linked: bool, state: string, last_error: string|null, link: string|null, sent_at: string|null, in_classroom: bool}
     */
    public static function payload(ClassPost $post, ?bool $linked = null): array
    {
        $row = $post->relationLoaded('googlePost') ? $post->googlePost : $post->googlePost()->first();

        return [
            'linked' => $linked ?? ClassroomGoogleLink::of((int) $post->classroom_id, (int) $post->author_id) !== null,
            'state' => $row?->state ?? self::STATE_NOT_SENT,
            'last_error' => $row?->last_error,
            'link' => $row?->alternate_link,
            'sent_at' => $row?->sent_at?->toIso8601String(),
            'in_classroom' => $row?->isInClassroom() ?? false,
        ];
    }

    /**
     * The teachers who linked a classroom (for the `linked` of each post of a list).
     *
     * @return list<int>
     */
    public static function linkedAuthors(int $classroomId): array
    {
        return ClassroomGoogleLink::query()->where('classroom_id', $classroomId)->pluck('owner_user_id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Whether a changed attachment list is refused: Classroom does not patch
     * materials, so the files of a copy that exists are locked.
     *
     * @param  list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>  $attachments
     */
    public static function attachmentsChanged(ClassPost $post, array $attachments): bool
    {
        return AttachmentInput::signature($post->attachments()->get()) !== AttachmentInput::rowsSignature($attachments);
    }

    public static function errorText(GoogleApiException $e): string
    {
        return match ($e->kind) {
            GoogleApiException::SCOPE_MISSING => GoogleAccount::CLASS_POSTS_RECONNECT_MESSAGE,
            GoogleApiException::INVALID_GRANT => 'ต้องเชื่อมบัญชี Google ใหม่ แล้วกดส่งอีกครั้ง',
            GoogleApiException::PERMISSION_DENIED, GoogleApiException::PROJECT_PERMISSION_DENIED => 'Google ไม่อนุญาตให้โพสต์ในคอร์สนี้ (บัญชีอาจไม่ได้เป็นครูของคอร์ส)',
            GoogleApiException::NOT_FOUND => 'ไม่พบคอร์ส หัวข้อ หรือโพสต์นี้ใน Google Classroom แล้ว',
            GoogleApiException::UNAVAILABLE => 'ติดต่อ Google ไม่ได้ ลองส่งอีกครั้งภายหลัง',
            GoogleApiException::NOT_CONFIGURED, GoogleApiException::API_DISABLED => GoogleErrors::shortText($e),
            default => mb_substr('Google ไม่รับโพสต์นี้'.($e->googleMessage !== null && $e->googleMessage !== '' ? ': '.$e->googleMessage : ''), 0, 250),
        };
    }

    private function patch(GoogleApi $api, ClassPostGooglePost $row, ClassPost $post, ?string $topicId): void
    {
        if ($post->kind === ClassPost::KIND_ANNOUNCEMENT) {
            $api->patchAnnouncement($row->course_id, (string) $row->google_id, ['text' => self::announcementText($post)], ['text']);

            return;
        }
        $body = ['title' => (string) $post->title, 'description' => (string) ($post->body ?? ''), 'topicId' => $topicId ?? ''];
        $api->patchCourseWorkMaterial($row->course_id, (string) $row->google_id, $body, ['title', 'description', 'topicId']);
    }

    /** An announcement needs text: the body, else the names of what is attached. */
    private static function announcementText(ClassPost $post): string
    {
        $body = trim((string) ($post->body ?? ''));
        if ($body !== '') {
            return $body;
        }

        return $post->attachments->map(fn (ClassPostAttachment $a) => $a->title)->filter()->implode("\n") ?: 'ไฟล์แนบ';
    }

    /**
     * @param  list<array<string, mixed>>  $materials
     * @param  list<string>|null  $studentIds
     * @return array<string, mixed>
     */
    private static function announcementBody(ClassPost $post, array $materials, ?array $studentIds): array
    {
        return array_filter([
            'text' => self::announcementText($post),
            'materials' => $materials,
            'state' => 'PUBLISHED',
            ...self::assignees($studentIds),
        ], fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * @param  list<array<string, mixed>>  $materials
     * @param  list<string>|null  $studentIds
     * @return array<string, mixed>
     */
    private static function materialBody(ClassPost $post, array $materials, ?string $topicId, ?array $studentIds): array
    {
        return array_filter([
            'title' => (string) $post->title,
            'description' => $post->body,
            'materials' => $materials,
            'state' => 'PUBLISHED',
            'topicId' => $topicId,
            ...self::assignees($studentIds),
        ], fn ($v) => $v !== [] && $v !== null);
    }

    /**
     * @param  list<string>|null  $studentIds
     * @return array<string, mixed>
     */
    private static function assignees(?array $studentIds): array
    {
        return $studentIds === null ? [] : [
            'assigneeMode' => 'INDIVIDUAL_STUDENTS',
            'individualStudentsOptions' => ['studentIds' => $studentIds],
        ];
    }

    private function fail(ClassPostGooglePost $row, string $reason): string
    {
        $row->state = ClassPostGooglePost::STATE_FAILED;
        $row->last_error = mb_substr($reason, 0, 255);
        $row->save();
        Log::warning('google.class_post_not_sent', ['post_id' => $row->class_post_id, 'reason' => $reason]);

        return self::FAILED;
    }
}
