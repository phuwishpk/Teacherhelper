<?php

namespace App\Domain\Google;

use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Domain\Worksheets\WorksheetLayoutException;
use App\Domain\Worksheets\WorksheetPdfRenderer;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Layout;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * "โพสต์ลง Classroom" (DESIGN §18.2, §18.6 POST /assignments/{id}/google-post):
 * creates a PUBLISHED courseWork in the linked course with maxPoints = the
 * assignment's full marks and the photo instructions, optionally with the
 * anonymous spare worksheet (§18.3: QR student_id 0, no name) uploaded to the
 * teacher's Drive and attached as a VIEW material.
 *
 * Only courseWork created here can take grades from EduVision later, so an
 * assignment is posted at most once (409 already_posted); a cache lock stops
 * a double tap from creating two.
 */
final class CourseWorkPoster
{
    public const INSTRUCTIONS = 'ทำบนใบงานที่ได้รับ ถ่ายรูปทุกหน้าให้เห็นมุมทั้ง 4 แล้วส่งที่นี่';

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
        $layout = $assignment->isReady() ? $assignment->currentLayout() : null;
        if ($layout === null) {
            throw new ApiException('โพสต์ได้เฉพาะการบ้านที่พร้อมพิมพ์แล้ว (อนุมัติ rubric และสร้าง layout ก่อน)', 'assignment_not_ready', 409);
        }
        $link = GoogleRoster::linkOf($assignment->classroom()->firstOrFail());
        $this->accounts->accountOf($teacher);

        $attach = $input['attach_blank_worksheet'];
        $pdf = $attach ? $this->spareWorksheet($assignment, $layout) : null;
        $fileName = 'ใบงานสำรอง '.$assignment->title.'.pdf';

        return $this->accounts->call($teacher, function (GoogleApi $api) use ($teacher, $assignment, $link, $input, $pdf, $fileName) {
            $fileId = $pdf !== null ? $api->uploadPdf($fileName, $pdf) : null;

            try {
                $work = $api->createCourseWork($link->course_id, $this->courseWork($assignment, $input, $fileId, $fileName));
            } catch (Throwable $e) {
                if ($fileId !== null) {
                    try {
                        $api->deleteDriveFile($fileId);
                    } catch (GoogleApiException) {
                        // The empty spare sheet stays in the teacher's Drive; harmless.
                    }
                }

                throw $e;
            }
            if ($work['id'] === '') {
                throw new GoogleApiException(GoogleApiException::BAD_REQUEST, 'courseWork.create answered without an id');
            }

            $posted = AssignmentGoogleLink::create([
                'assignment_id' => $assignment->id,
                'course_work_id' => $work['id'],
                'alternate_link' => $work['alternate_link'],
                'drive_file_id' => $fileId,
                'posted_by' => $teacher->id,
                'posted_at' => now(),
            ]);
            Log::info('google.posted', ['assignment_id' => $assignment->id, 'course_work_id' => $work['id'], 'spare_sheet' => $fileId !== null]);

            return $posted;
        }, GoogleRoster::COURSE_GONE);
    }

    /**
     * The courseWork resource (Classroom v1). Due date and time are UTC.
     *
     * @param  array{attach_blank_worksheet: bool, instructions?: string|null, due_at?: string|null}  $input
     * @return array<string, mixed>
     */
    public function courseWork(Assignment $assignment, array $input, ?string $fileId, string $fileName): array
    {
        $work = [
            'title' => $assignment->title,
            'description' => $this->description($input['instructions'] ?? null, $fileId !== null),
            'workType' => 'ASSIGNMENT',
            'state' => 'PUBLISHED',
            'maxPoints' => (float) $assignment->questions()->sum('max_points'),
        ];
        if ($fileId !== null) {
            $work['materials'] = [['driveFile' => ['driveFile' => ['id' => $fileId, 'title' => $fileName], 'shareMode' => 'VIEW']]];
        }

        $due = $this->dueAt($assignment, $input['due_at'] ?? null);
        if ($due !== null) {
            $work['dueDate'] = ['year' => $due->year, 'month' => $due->month, 'day' => $due->day];
            $work['dueTime'] = ['hours' => $due->hour, 'minutes' => $due->minute];
        }

        return $work;
    }

    private function description(?string $instructions, bool $spare): string
    {
        $lines = [self::INSTRUCTIONS];
        if ($spare) {
            $lines[] = 'ถ้าใบงานหาย ใช้ใบงานสำรองที่แนบไว้ พิมพ์แล้วเขียนชื่อและเลขที่ให้ครบ';
        }
        $instructions = trim((string) $instructions);
        if ($instructions !== '') {
            $lines[] = '';
            $lines[] = $instructions;
        }
        $lines[] = '';
        $appLink = trim((string) config('services.google.app_link'));
        $lines[] = 'คะแนนจะแสดงที่นี่เมื่อครูตรวจเสร็จ ดูคำอธิบายรายข้อได้ในแอป EduVision'.($appLink !== '' ? " {$appLink}" : '');

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

    /**
     * The anonymous spare sheet (§18.3), rendered with the same renderer and
     * layout check as the class worksheets.
     */
    private function spareWorksheet(Assignment $assignment, Layout $layout): string
    {
        try {
            return app(WorksheetPdfRenderer::class)->render($assignment, $layout, [null]);
        } catch (QrSigningKeyMissing) {
            throw new ApiException(
                'ยังสร้างใบงานสำรองไม่ได้ เพราะเซิร์ฟเวอร์ยังไม่ได้ตั้งค่า QR_SIGNING_KEY กรุณาแจ้งผู้ดูแลระบบ',
                'qr_key_missing',
                503,
            );
        } catch (WorksheetLayoutException $e) {
            throw new ApiException($e->getMessage(), 'assignment_not_ready', 409);
        }
    }
}
