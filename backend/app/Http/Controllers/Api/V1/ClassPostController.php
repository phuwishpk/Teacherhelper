<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ClassPosts\ClassPostGoogle;
use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\ClassPost;
use App\Models\ClassPostAttachment;
use App\Models\ClassPostFile;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Class posts of the teacher (DESIGN §30.1, §30.8): announcements and
 * materials with files and links, for one or several classrooms, for
 * everyone or chosen students, published now, kept as a draft or scheduled.
 * Every teacher of a classroom (homeroom or subject, ClassroomAccess) reads
 * its published posts and writes their own; only the author edits or
 * deletes a post.
 */
class ClassPostController extends Controller
{
    public const MAX_CLASSROOMS = 20;

    public const MAX_BODY = 10000;

    public const LIST_LIMIT = 200;

    public function __construct(private readonly ClassPostGoogle $google) {}

    /**
     * GET /api/v1/classrooms/{id}/posts -> {data: [post]}: the published
     * posts of every teacher and the caller's own drafts and scheduled
     * posts, newest first (a draft sorts by its last change).
     */
    public function index(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $classroom = ClassroomAccess::classrooms($teacher)->findOrFail($id);

        $posts = ClassPosts::forTeacher($teacher)
            ->where('class_posts.classroom_id', $classroom->id)
            ->with(ClassPosts::RELATIONS)
            ->orderByRaw('COALESCE(class_posts.published_at, class_posts.scheduled_at, class_posts.updated_at) DESC')
            ->orderByDesc('class_posts.id')
            ->limit(self::LIST_LIMIT)
            ->get();

        $linked = ClassPostGoogle::linkedAuthors($classroom->id);

        return response()->json(['data' => $posts->map(fn (ClassPost $p) => ClassPosts::payload($p, $teacher, in_array((int) $p->author_id, $linked, true)))->all()]);
    }

    /**
     * POST /api/v1/class-posts {classroom_ids[], kind, title?, body?,
     * course_id?, unit_id?, topic?, audience?, student_ids?, state,
     * scheduled_at?, send_to_google?, attachments?} -> 201 {data: [post]},
     * one post per classroom.
     */
    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $data = $request->validate([
            'classroom_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_CLASSROOMS],
            'classroom_ids.*' => ['integer', 'distinct'],
            'kind' => ['required', Rule::in(ClassPost::KINDS)],
            'state' => ['required', Rule::in(ClassPost::STATES)],
            ...self::rules(),
        ], self::messages());

        $classrooms = [];
        foreach ($data['classroom_ids'] as $classroomId) {
            $classrooms[] = self::classroom($teacher, (int) $classroomId, 'classroom_ids');
        }
        $audience = $data['audience'] ?? ClassPost::AUDIENCE_ALL;
        if ($audience === ClassPost::AUDIENCE_STUDENTS && count($classrooms) !== 1) {
            throw self::invalid('student_ids', 'เลือกนักเรียนรายคนได้เมื่อโพสต์ห้องเดียว');
        }
        $course = self::course($teacher, $classrooms, $data['course_id'] ?? null);
        [$unitId, $topic] = self::topic($course, $data['unit_id'] ?? null, $data['topic'] ?? null);
        $content = self::content($data['kind'], $data['title'] ?? null, $data['body'] ?? null);
        $attachments = self::attachments($teacher, $data['attachments'] ?? []);
        self::assertNotEmpty($data['kind'], $content['body'], $attachments);
        $studentIds = $audience === ClassPost::AUDIENCE_STUDENTS ? self::students($classrooms[0], $data['student_ids'] ?? []) : [];
        [$state, $scheduledAt] = self::schedule($data['state'], $data['scheduled_at'] ?? null);

        $posts = DB::transaction(function () use ($teacher, $classrooms, $data, $course, $unitId, $topic, $content, $attachments, $audience, $studentIds, $state, $scheduledAt) {
            $batchId = count($classrooms) > 1 ? (string) Str::uuid() : null;
            $posts = [];
            foreach ($classrooms as $classroom) {
                $post = ClassPost::create([
                    'classroom_id' => $classroom->id,
                    'author_id' => $teacher->id,
                    'course_id' => $course?->id,
                    'kind' => $data['kind'],
                    'title' => $content['title'],
                    'body' => $content['body'],
                    'unit_id' => $unitId,
                    'topic' => $topic,
                    'audience' => $audience,
                    'state' => $state === ClassPost::STATE_PUBLISHED ? ClassPost::STATE_DRAFT : $state,
                    'scheduled_at' => $scheduledAt,
                    'batch_id' => $batchId,
                    'send_to_google' => (bool) ($data['send_to_google'] ?? true),
                ]);
                self::writeAttachments($post, $attachments);
                $post->recipients()->sync($studentIds);
                if ($state === ClassPost::STATE_PUBLISHED) {
                    ClassPosts::publish($post);
                }
                $posts[] = $post;
            }

            return $posts;
        });

        return response()->json([
            'data' => array_map(fn (ClassPost $p) => ClassPosts::payload($p->load(ClassPosts::RELATIONS), $teacher), $posts),
        ], 201);
    }

    /** GET /api/v1/class-posts/{id} -> {data: post} */
    public function show(Request $request, int $id): JsonResponse
    {
        $post = ClassPosts::forTeacher($request->user())->with(ClassPosts::RELATIONS)->findOrFail($id);

        return response()->json(['data' => ClassPosts::payload($post, $request->user())]);
    }

    /**
     * PUT /api/v1/class-posts/{id} {title?, body?, course_id?, unit_id?,
     * topic?, audience?, student_ids?, state?, scheduled_at?,
     * send_to_google?, attachments?} -> {data: post}. The author only (403
     * not_post_author). A published post stays published (409
     * post_already_published); attachments, when sent, replace the list.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $teacher = $request->user();
        $post = $this->own($request, $id);
        ClosedClassrooms::assertOpen($post->classroom);
        $data = $request->validate([
            'state' => ['sometimes', Rule::in(ClassPost::STATES)],
            ...self::rules(),
        ], self::messages());

        $classroom = $post->classroom()->firstOrFail();
        $courseId = array_key_exists('course_id', $data) ? $data['course_id'] : $post->course_id;
        $course = self::course($teacher, [$classroom], $courseId);
        $unitGiven = array_key_exists('unit_id', $data) || array_key_exists('topic', $data);
        // A unit of another course cannot stay when the course changes.
        $unitInput = array_key_exists('unit_id', $data) ? $data['unit_id'] : ($course?->id === $post->course_id ? $post->unit_id : null);
        $topicInput = array_key_exists('topic', $data) ? $data['topic'] : ($unitGiven ? null : $post->topic);
        [$unitId, $topic] = self::topic($course, $unitInput, $topicInput);
        $content = self::content(
            $post->kind,
            array_key_exists('title', $data) ? $data['title'] : $post->title,
            array_key_exists('body', $data) ? $data['body'] : $post->body,
        );
        $attachments = array_key_exists('attachments', $data) ? self::attachments($teacher, $data['attachments'] ?? []) : null;
        if ($attachments !== null && ($post->googlePost()->first()?->isInClassroom() ?? false) && ClassPostGoogle::attachmentsChanged($post, $attachments)) {
            // Classroom does not patch materials (DESIGN §30.4): the files of a sent copy stay as they are.
            throw new ApiException('โพสต์นี้ส่งไป Google Classroom แล้ว แก้ไฟล์แนบไม่ได้ ลบโพสต์แล้วสร้างใหม่ถ้าต้องเปลี่ยนไฟล์', 'attachments_locked', 409);
        }
        $alreadyPublished = $post->isPublished();
        $audience = $data['audience'] ?? $post->audience;
        $studentIds = null;
        if ($audience === ClassPost::AUDIENCE_STUDENTS && (array_key_exists('student_ids', $data) || $post->audience !== ClassPost::AUDIENCE_STUDENTS)) {
            $studentIds = self::students($classroom, $data['student_ids'] ?? []);
        }
        if ($audience === ClassPost::AUDIENCE_ALL) {
            $studentIds = [];
        }
        $stateGiven = array_key_exists('state', $data) || array_key_exists('scheduled_at', $data);
        if ($post->isPublished() && array_key_exists('state', $data) && $data['state'] !== ClassPost::STATE_PUBLISHED) {
            throw new ApiException('โพสต์นี้เผยแพร่แล้ว เปลี่ยนกลับเป็นฉบับร่างไม่ได้', 'post_already_published', 409);
        }
        [$state, $scheduledAt] = $post->isPublished() || ! $stateGiven
            ? [$post->state, $post->scheduled_at]
            : self::schedule($data['state'] ?? $post->state, array_key_exists('scheduled_at', $data) ? $data['scheduled_at'] : $post->scheduled_at?->toIso8601String());

        DB::transaction(function () use ($post, $data, $course, $unitId, $topic, $content, $attachments, $audience, $studentIds, $state, $scheduledAt, $alreadyPublished) {
            $post = ClassPost::query()->lockForUpdate()->findOrFail($post->id);
            $removed = [];
            if ($attachments !== null) {
                $removed = $post->attachments()->whereNotNull('class_post_file_id')->pluck('class_post_file_id')->map(fn ($id) => (int) $id)->all();
                $post->attachments()->delete();
                self::writeAttachments($post, $attachments);
            }
            $count = $post->attachments()->count();
            self::assertNotEmpty($post->kind, $content['body'], array_fill(0, $count, null));
            $post->fill([
                'course_id' => $course?->id,
                'title' => $content['title'],
                'body' => $content['body'],
                'unit_id' => $unitId,
                'topic' => $topic,
                'audience' => $audience,
            ]);
            if (array_key_exists('send_to_google', $data)) {
                $post->send_to_google = (bool) $data['send_to_google'];
            }
            if ($studentIds !== null) {
                $post->recipients()->sync($studentIds);
            }
            if (! $post->isPublished()) {
                $post->state = $state === ClassPost::STATE_PUBLISHED ? $post->state : $state;
                $post->scheduled_at = $state === ClassPost::STATE_SCHEDULED ? $scheduledAt : null;
            }
            $post->save();
            if (! $post->isPublished() && $state === ClassPost::STATE_PUBLISHED) {
                ClassPosts::publish($post); // its listener queues the Classroom copy
            } elseif ($alreadyPublished) {
                if ($post->send_to_google) {
                    $this->google->queue($post);
                } else {
                    $this->google->unsend($post);
                }
            }
            ClassPosts::deleteUnused($removed);
        });

        return response()->json(['data' => ClassPosts::payload($post->refresh()->load(ClassPosts::RELATIONS), $teacher)]);
    }

    /** DELETE /api/v1/class-posts/{id} -> 204; the author only. Files no other post uses go with it. */
    public function destroy(Request $request, int $id): Response
    {
        $post = $this->own($request, $id);
        ClosedClassrooms::assertOpen($post->classroom);

        DB::transaction(function () use ($post) {
            $fileIds = $post->attachments()->whereNotNull('class_post_file_id')->pluck('class_post_file_id')->map(fn ($id) => (int) $id)->all();
            $this->google->unsend($post);
            $post->delete(); // attachments and recipients cascade
            ClassPosts::deleteUnused($fileIds);
        });

        return response()->noContent();
    }

    /**
     * POST /api/v1/class-posts/{id}/google/retry -> 202 {data: google}: the
     * author sends a failed (or never sent) Classroom copy again (DESIGN
     * §30.4). 422 classroom_not_linked when the author has no link.
     */
    public function googleRetry(Request $request, int $id): JsonResponse
    {
        $post = $this->own($request, $id);
        ClosedClassrooms::assertOpen($post->classroom);
        if (! $post->isPublished()) {
            throw new ApiException('โพสต์นี้ยังไม่ได้เผยแพร่', 'post_not_published', 409);
        }
        if (! $post->send_to_google) {
            $post->forceFill(['send_to_google' => true])->save();
        }
        if ($this->google->retry($post) === null) {
            throw new ApiException('ห้องเรียนนี้ยังไม่ได้ผูกกับ Google Classroom ของคุณ', 'classroom_not_linked', 422);
        }

        return response()->json(['data' => ClassPostGoogle::payload($post->refresh())], 202);
    }

    /** The caller's own post (404 when not visible, 403 not_post_author when another teacher's). */
    private function own(Request $request, int $id): ClassPost
    {
        $post = ClassPosts::forTeacher($request->user())->with('classroom')->findOrFail($id);
        if ((int) $post->author_id !== (int) $request->user()->id) {
            throw new ApiException('แก้หรือลบได้เฉพาะโพสต์ของตัวเอง', 'not_post_author', 403);
        }

        return $post;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private static function rules(): array
    {
        $max = (int) config('eduvision.class_posts.max_attachments');

        return [
            'title' => ['sometimes', 'nullable', 'string', 'max:200'],
            'body' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_BODY],
            'course_id' => ['sometimes', 'nullable', 'integer'],
            'unit_id' => ['sometimes', 'nullable', 'integer'],
            'topic' => ['sometimes', 'nullable', 'string', 'max:100'],
            'audience' => ['sometimes', Rule::in([ClassPost::AUDIENCE_ALL, ClassPost::AUDIENCE_STUDENTS])],
            'student_ids' => ['sometimes', 'array', 'max:500'],
            'student_ids.*' => ['integer', 'distinct'],
            'scheduled_at' => ['sometimes', 'nullable', 'date'],
            'send_to_google' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:'.$max],
            'attachments.*.file_id' => ['sometimes', 'integer'],
            'attachments.*.url' => ['sometimes', 'string', 'max:2000'],
            'attachments.*.title' => ['sometimes', 'nullable', 'string', 'max:200'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private static function messages(): array
    {
        return [
            'classroom_ids.required' => 'เลือกห้องเรียนอย่างน้อยหนึ่งห้อง',
            'classroom_ids.max' => 'เลือกได้ไม่เกิน '.self::MAX_CLASSROOMS.' ห้องต่อครั้ง',
            'kind.required' => 'เลือกชนิดของโพสต์',
            'kind.in' => 'ชนิดของโพสต์ไม่ถูกต้อง',
            'state.required' => 'เลือกว่าจะเผยแพร่ บันทึกฉบับร่าง หรือตั้งเวลา',
            'state.in' => 'สถานะของโพสต์ไม่ถูกต้อง',
            'title.max' => 'ชื่อเรื่องยาวไม่เกิน 200 ตัวอักษร',
            'body.max' => 'ข้อความยาวไม่เกิน '.number_format(self::MAX_BODY).' ตัวอักษร',
            'topic.max' => 'ชื่อหัวข้อยาวไม่เกิน 100 ตัวอักษร',
            'scheduled_at.date' => 'วันเวลาที่ตั้งไว้ไม่ถูกต้อง',
            'attachments.max' => 'แนบไฟล์และลิงก์ได้ไม่เกิน '.(int) config('eduvision.class_posts.max_attachments').' รายการต่อโพสต์',
            'attachments.*.url.max' => 'ลิงก์ยาวเกินไป',
            'attachments.*.title.max' => 'ชื่อของไฟล์แนบยาวไม่เกิน 200 ตัวอักษร',
        ];
    }

    /** A classroom the teacher teaches (homeroom or subject), still open. */
    private static function classroom(User $teacher, int $id, string $field): Classroom
    {
        $classroom = ClassroomAccess::classrooms($teacher)->find($id);
        if ($classroom === null) {
            throw self::invalid($field, 'เลือกห้องเรียนของคุณ');
        }
        ClosedClassrooms::assertOpen($classroom);

        return $classroom;
    }

    /**
     * The teacher's own course bound to every chosen classroom, or null. A
     * subject teacher is in the classroom through a course only, so must
     * name it.
     *
     * @param  list<Classroom>  $classrooms
     */
    private static function course(User $teacher, array $classrooms, mixed $courseId): ?Course
    {
        foreach ($classrooms as $classroom) {
            $access = ClassroomAccess::for($teacher, $classroom);
            if ($access === null) {
                throw self::invalid('classroom_ids', 'เลือกห้องเรียนของคุณ');
            }
            if ($courseId === null) {
                if (! $access->isHomeroom()) {
                    throw self::invalid('course_id', 'เลือกรายวิชาของคุณที่สอนในห้องนี้');
                }

                continue;
            }
            if (! $access->teaches((int) $courseId)) {
                throw self::invalid('course_id', 'เลือกรายวิชาของคุณที่ผูกกับทุกห้องที่เลือก');
            }
        }

        return $courseId === null ? null : Course::query()->findOrFail((int) $courseId);
    }

    /**
     * unit_id (a unit of the post's course) or a free topic, never both.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private static function topic(?Course $course, mixed $unitId, mixed $topic): array
    {
        if ($unitId !== null) {
            if ($course === null || ! Unit::query()->whereKey((int) $unitId)->where('course_id', $course->id)->exists()) {
                throw self::invalid('unit_id', 'เลือกหน่วยการเรียนรู้ของรายวิชานี้');
            }

            return [(int) $unitId, null];
        }
        $topic = self::text($topic);

        return [null, $topic];
    }

    /**
     * @return array{title: string|null, body: string|null}
     */
    private static function content(string $kind, mixed $title, mixed $body): array
    {
        $title = self::text($title);
        $body = self::text($body, false);
        if ($kind === ClassPost::KIND_MATERIAL && $title === null) {
            throw self::invalid('title', 'กรอกชื่อเรื่องของเอกสารประกอบ');
        }

        return ['title' => $kind === ClassPost::KIND_MATERIAL ? $title : null, 'body' => $body];
    }

    /**
     * An announcement needs something to read: text or an attachment.
     *
     * @param  array<int, mixed>  $attachments
     */
    private static function assertNotEmpty(string $kind, ?string $body, array $attachments): void
    {
        if ($kind === ClassPost::KIND_ANNOUNCEMENT && $body === null && $attachments === []) {
            throw self::invalid('body', 'พิมพ์ข้อความหรือแนบไฟล์อย่างน้อยหนึ่งอย่าง');
        }
    }

    /**
     * @param  array<int, mixed>  $input
     * @return list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>
     */
    private static function attachments(User $teacher, array $input): array
    {
        $rows = [];
        $seen = [];
        // The validator returns the items grouped by rule, not in the order sent.
        ksort($input);
        foreach (array_values($input) as $i => $item) {
            $item = is_array($item) ? $item : [];
            $hasFile = isset($item['file_id']);
            $url = isset($item['url']) ? trim((string) $item['url']) : '';
            if ($hasFile === ($url !== '')) {
                throw self::invalid("attachments.{$i}", 'ไฟล์แนบแต่ละรายการต้องเป็นไฟล์หรือลิงก์อย่างใดอย่างหนึ่ง');
            }
            if ($hasFile) {
                $file = ClassPostFile::query()->where('owner_id', $teacher->id)->find((int) $item['file_id']);
                if ($file === null || isset($seen[$file->id])) {
                    throw self::invalid("attachments.{$i}.file_id", 'ไม่พบไฟล์ที่แนบ หรือแนบไฟล์เดียวกันซ้ำ');
                }
                $seen[$file->id] = true;
                $rows[] = ['kind' => ClassPostAttachment::KIND_FILE, 'file' => $file, 'url' => null, 'title' => self::text($item['title'] ?? null) ?? (string) $file->original_name];

                continue;
            }
            $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
            if (! in_array($scheme, ['http', 'https'], true) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw self::invalid("attachments.{$i}.url", 'ลิงก์ต้องขึ้นต้นด้วย http:// หรือ https://');
            }
            $rows[] = ['kind' => ClassPostAttachment::KIND_LINK, 'file' => null, 'url' => $url, 'title' => self::text($item['title'] ?? null) ?? Str::limit($url, 197)];
        }

        return $rows;
    }

    /**
     * @param  list<array{kind: string, file: ClassPostFile|null, url: string|null, title: string}>  $attachments
     */
    private static function writeAttachments(ClassPost $post, array $attachments): void
    {
        foreach ($attachments as $position => $row) {
            ClassPostAttachment::create([
                'class_post_id' => $post->id,
                'kind' => $row['kind'],
                'class_post_file_id' => $row['file']?->id,
                'url' => $row['url'],
                'title' => $row['title'],
                'position' => $position,
            ]);
        }
    }

    /**
     * @param  array<int, mixed>  $ids
     * @return list<int>
     */
    private static function students(Classroom $classroom, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw self::invalid('student_ids', 'เลือกนักเรียนอย่างน้อยหนึ่งคน');
        }
        $found = DB::table('classroom_students')->where('classroom_id', $classroom->id)->whereIn('student_id', $ids)->count();
        if ($found !== count($ids)) {
            throw self::invalid('student_ids', 'เลือกได้เฉพาะนักเรียนของห้องนี้');
        }
        sort($ids);

        return $ids;
    }

    /**
     * @return array{0: string, 1: Carbon|null}
     */
    private static function schedule(string $state, mixed $scheduledAt): array
    {
        if ($state !== ClassPost::STATE_SCHEDULED) {
            return [$state, null];
        }
        $at = $scheduledAt === null || $scheduledAt === '' ? null : Carbon::parse((string) $scheduledAt)->utc();
        if ($at === null || $at->lessThanOrEqualTo(now())) {
            throw self::invalid('scheduled_at', 'ตั้งเวลาเผยแพร่เป็นเวลาในอนาคต');
        }

        return [$state, $at];
    }

    /** Trimmed text, null when empty; a title or topic also loses its line breaks. */
    private static function text(mixed $value, bool $singleLine = true): ?string
    {
        $text = trim((string) ($value ?? ''));
        if ($singleLine) {
            $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        return $text === '' ? null : $text;
    }

    private static function invalid(string $field, string $message): ApiException
    {
        return new ApiException($message, 'validation_failed', 422, [$field => [$message]]);
    }
}
