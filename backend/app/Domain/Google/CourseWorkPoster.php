<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
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

        return $this->accounts->call($teacher, function (GoogleApi $api) use ($teacher, $assignment, $link, $input) {
            $work = $api->createCourseWork($link->course_id, $this->courseWork($assignment, $input));
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
    public function courseWork(Assignment $assignment, array $input): array
    {
        $work = [
            'title' => $assignment->title,
            'description' => $this->description($input['instructions'] ?? null),
            'workType' => 'ASSIGNMENT',
            'state' => 'PUBLISHED',
            'maxPoints' => (float) $assignment->questions()->sum('max_points'),
        ];

        $due = $this->dueAt($assignment, $input['due_at'] ?? null);
        if ($due !== null) {
            $work['dueDate'] = ['year' => $due->year, 'month' => $due->month, 'day' => $due->day];
            $work['dueTime'] = ['hours' => $due->hour, 'minutes' => $due->minute];
        }

        return $work;
    }

    private function description(?string $instructions): string
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
