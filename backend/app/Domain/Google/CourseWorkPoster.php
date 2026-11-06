<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * "โพสต์ลง Classroom" (DESIGN §18.2, §18.6 POST /assignments/{id}/google-post):
 * creates a PUBLISHED courseWork in the linked course with maxPoints = the
 * assignment's full marks and the photo instructions.
 *
 * No spare worksheet any more (DESIGN §19.4 drops §18.3's anonymous sheet):
 * a hand-in is graded from the whole page and belongs to whoever handed it
 * in, so any paper works. attach_blank_worksheet is still accepted from
 * older apps and ignored.
 *
 * Only courseWork created here can take grades from Krucheck later, so an
 * assignment is posted at most once (409 already_posted); a cache lock stops
 * a double tap from creating two.
 */
final class CourseWorkPoster
{
    public const INSTRUCTIONS = 'ถ่ายรูปงานทุกหน้าให้เห็นตัวหนังสือชัดเจน หรือแนบไฟล์ PDF แล้วส่งที่นี่';

    public function __construct(private readonly GoogleAccounts $accounts) {}

    /**
     * @param  array{attach_blank_worksheet: bool, instructions?: string|null, due_at?: string|null}  $input
     *
     * @throws ApiException
     */
    public function post(User $teacher, Assignment $assignment, array $input): AssignmentGoogleLink
    {
        $lock = Cache::lock("google-post:{$assignment->id}", 120);
        if (! $lock->get()) {
            throw new ApiException('กำลังโพสต์การบ้านนี้อยู่ รอสักครู่', 'google_post_in_progress', 409);
        }

        try {
            return $this->postLocked($teacher, $assignment->refresh(), $input);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{attach_blank_worksheet: bool, instructions?: string|null, due_at?: string|null}  $input
     */
    private function postLocked(User $teacher, Assignment $assignment, array $input): AssignmentGoogleLink
    {
        if ($assignment->googleLink()->exists()) {
            throw new ApiException('การบ้านนี้โพสต์ลง Google Classroom แล้ว', 'already_posted', 409);
        }
        // A freeform assignment is ready once its key is approved; it has no layout (§19.5).
        $ready = $assignment->isReady() && ($assignment->isFreeform() || $assignment->currentLayout() !== null);
        if (! $ready) {
            throw new ApiException('โพสต์ได้เฉพาะการบ้านที่พร้อมพิมพ์แล้ว (อนุมัติ rubric และสร้าง layout ก่อน)', 'assignment_not_ready', 409);
        }
        // The course of the teacher who manages the work (DESIGN §24.10).
        $link = GoogleRoster::linkOfAssignment($assignment);
        $this->accounts->accountOf($teacher);
        // The note of the post dialog is the assignment's text from now on: Krucheck and Classroom read the same (§30.12).
        $note = trim((string) ($input['instructions'] ?? ''));
        if ($note !== '' && $note !== (string) $assignment->instructions) {
            $assignment->forceFill(['instructions' => $note])->save();
        }
        $assignment->loadMissing('unit');

        return $this->accounts->call($teacher, function (GoogleApi $api) use ($teacher, $assignment, $link, $input) {
            $materials = ClassroomMaterials::of($api, $assignment->attachments()->with('file')->get());
            if ($materials === null) {
                throw new ApiException('ไฟล์แนบของการบ้านหายไปจากเซิร์ฟเวอร์ แนบใหม่แล้วโพสต์อีกครั้ง', 'attachment_missing', 409);
            }
            $topicName = $assignment->topicName();
            $topicId = $topicName === null ? null : ClassroomTopics::idFor($api, $link->course_id, $topicName, $assignment->unit_id);
            $work = $api->createCourseWork($link->course_id, $this->courseWork($assignment, $input, $materials, $topicId, self::assignees($assignment)));
            if ($work['id'] === '') {
                throw new GoogleApiException(GoogleApiException::BAD_REQUEST, 'courseWork.create answered without an id');
            }

            $posted = AssignmentGoogleLink::create([
                'assignment_id' => $assignment->id,
                'course_work_id' => $work['id'],
                'alternate_link' => $work['alternate_link'],
                'drive_file_id' => null,
                'posted_by' => $teacher->id,
                'posted_at' => now(),
            ]);
            AssignmentGooglePosts::clear($assignment);
            Log::info('google.posted', ['assignment_id' => $assignment->id, 'course_work_id' => $work['id']]);

            return $posted;
        }, GoogleRoster::COURSE_GONE);
    }

    /**
     * The courseWork resource (Classroom v1). Due date and time are UTC.
     *
     * @param  array{attach_blank_worksheet: bool, instructions?: string|null, due_at?: string|null}  $input
     * @return array<string, mixed>
     */
    public function courseWork(Assignment $assignment, array $input, array $materials = [], ?string $topicId = null, ?array $studentIds = null): array
    {
        $work = [
            'title' => $assignment->title,
            'description' => $this->description($assignment, $input['instructions'] ?? $assignment->instructions),
            'workType' => 'ASSIGNMENT',
            'state' => 'PUBLISHED',
            'maxPoints' => (float) $assignment->questions()->sum('max_points'),
        ];
        if ($materials !== []) {
            $work['materials'] = $materials;
        }
        if ($topicId !== null) {
            $work['topicId'] = $topicId;
        }
        if ($studentIds !== null) {
            $work['assigneeMode'] = 'INDIVIDUAL_STUDENTS';
            $work['individualStudentsOptions'] = ['studentIds' => $studentIds];
        }

        $due = $this->dueAt($assignment, $input['due_at'] ?? null);
        if ($due !== null) {
            $work['dueDate'] = ['year' => $due->year, 'month' => $due->month, 'day' => $due->day];
            $work['dueTime'] = ['hours' => $due->hour, 'minutes' => $due->minute];
        }

        return $work;
    }

    /**
     * Homework for chosen students (DESIGN §30.13) goes to their Classroom
     * accounts; null for the whole class. 422 no_google_students when none
     * of them is paired, rather than reaching everyone by mistake.
     *
     * @return list<string>|null
     */
    private static function assignees(Assignment $assignment): ?array
    {
        if (! $assignment->isForStudents()) {
            return null;
        }
        $ids = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->whereIn('student_id', $assignment->recipients()->select('users.id'))
            ->whereNotNull('google_user_id')
            ->orderBy('google_user_id')
            ->pluck('google_user_id')
            ->map(fn ($id) => (string) $id)
            ->all();
        if ($ids === []) {
            throw new ApiException('นักเรียนที่ได้รับมอบงานนี้ยังไม่มีใครจับคู่กับบัญชี Google จับคู่ในหน้ารายชื่อ Google ของห้องก่อน', 'no_google_students', 422);
        }

        return $ids;
    }

    private function description(Assignment $assignment, ?string $instructions): string
    {
        $lines = [self::INSTRUCTIONS];
        $maxPages = (int) config('eduvision.submissions.max_pages');
        $maxMb = (int) config('eduvision.submissions.max_file_mb');
        $lines[] = "ส่งได้ไม่เกิน {$maxPages} หน้า ไฟล์ละไม่เกิน {$maxMb} MB (รูป JPEG, PNG, HEIC หรือ PDF)";
        $instructions = trim((string) $instructions);
        if ($instructions !== '') {
            $lines[] = '';
            $lines[] = $instructions;
        }
        $lines[] = '';
        $lines[] = 'ส่งงานและดูผลรายข้อในแอป Krucheck: '.AssignmentLinks::url($assignment->id);
        $appLink = trim((string) config('services.google.app_link'));
        $lines[] = 'คะแนนจะแสดงที่นี่เมื่อครูตรวจเสร็จ ดูคำอธิบายรายข้อได้ในแอป Krucheck'.($appLink !== '' ? " {$appLink}" : '');

        return implode("\n", $lines);
    }

    /** The requested due time, else the assignment's own if still ahead (Classroom refuses a past one). */
    private function dueAt(Assignment $assignment, ?string $requested): ?CarbonImmutable
    {
        if ($requested !== null && $requested !== '') {
            return CarbonImmutable::parse($requested)->utc();
        }
        if ($assignment->due_at !== null && $assignment->due_at->isFuture()) {
            return CarbonImmutable::parse($assignment->due_at)->utc();
        }

        return null;
    }
}
