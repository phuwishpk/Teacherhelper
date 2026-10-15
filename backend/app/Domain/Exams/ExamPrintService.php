<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Worksheets\ArucoMarkers;
use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Exceptions\ApiException;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderAnswerSheetsJob;
use App\Jobs\RenderExamBookletJob;
use App\Models\Assignment;
use App\Models\ExamVersion;
use App\Models\Layout;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * POST /exams/{id}/prints (DESIGN §22.6, §22.15, §22.16):
 *
 * - exam_booklet {version_no}: one PDF of that version (RenderExamBookletJob).
 *   An app exam needs its key approved (409 answer_key_not_approved); every
 *   grading method needs at least one question and every question approved
 *   and with a prompt (422 answer_key_incomplete, errors.questions).
 * - answer_sheet {student_ids?}: the classroom's roster (or the chosen
 *   students) in chunks of eduvision.exams.sheet_batch_size, then
 *   MergeWorksheetsJob. App exams only (422 exam_manual_grading) with the key
 *   approved (409 answer_key_not_approved).
 * - key_sheet: the teacher's key sheet, student_id 0, before the key is
 *   approved too (it is how the key can be filled in). App exams only.
 * - An exam with sheet_identity = code (§22.19) prints one shared answer
 *   sheet for the whole class (the teacher copies it): student_ids is
 *   refused (422 errors.student_ids) and the roster may be empty.
 *
 * Sheets need QR_SIGNING_KEY (503 qr_key_missing) and at most two pages
 * (422 exam_sheet_overflow). They share one layout per layout_version in
 * `layouts`: the current one when the grid is unchanged, else a new version.
 * The first print of any kind locks the structure (structure_locked_at) after
 * bringing the shuffled versions up to date. The jobs are dispatched after
 * the row lock is released, on the `pdf` queue.
 */
class ExamPrintService
{
    /**
     * @param  array<string, mixed>  $input  {kind, version_no?, student_ids?}
     */
    public function queue(Assignment $exam, User $teacher, array $input): WorksheetPrint
    {
        $kind = $input['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, WorksheetPrint::EXAM_KINDS, true)) {
            throw ValidationException::withMessages(['kind' => 'เลือกสิ่งที่จะพิมพ์: เล่มข้อสอบ กระดาษคำตอบ หรือกระดาษเฉลย']);
        }
        $sheet = $kind !== WorksheetPrint::KIND_EXAM_BOOKLET;
        if ($sheet && $exam->isManualExam()) {
            // Before the server-setup check: a manual exam never has sheets, whatever the server has.
            throw new ApiException('ข้อสอบที่ครูตรวจเองไม่มีกระดาษคำตอบ พิมพ์ได้เฉพาะเล่มข้อสอบ', 'exam_manual_grading', 422);
        }
        if ($sheet && ! QrSigner::isConfigured()) {
            Log::error('exams.qr_key_missing', ['assignment_id' => $exam->id]);

            throw new ApiException(QrSigningKeyMissing::USER_MESSAGE, 'qr_key_missing', 503);
        }
        $requestedStudents = $kind === WorksheetPrint::KIND_ANSWER_SHEET ? self::studentIds($input['student_ids'] ?? null) : null;

        [$print, $jobs] = AssignmentLocked::run($exam->id, function (Assignment $exam) use ($kind, $sheet, $input, $requestedStudents, $teacher) {
            if ($sheet && $exam->isManualExam()) {
                throw new ApiException('ข้อสอบที่ครูตรวจเองไม่มีกระดาษคำตอบ พิมพ์ได้เฉพาะเล่มข้อสอบ', 'exam_manual_grading', 422);
            }
            $versionNo = $kind === WorksheetPrint::KIND_EXAM_BOOKLET ? self::versionNo($exam, $input['version_no'] ?? null) : null;
            $this->assertPrintable($exam, $kind);

            $plan = null;
            if ($sheet) {
                $plan = ExamSheetLayout::forExam($exam);
            }
            $studentIds = [];
            $shared = $kind === WorksheetPrint::KIND_ANSWER_SHEET && $exam->usesCodeSheets();
            if ($shared && $requestedStudents !== null) {
                throw ValidationException::withMessages([
                    'student_ids' => 'กระดาษคำตอบแบบฝนเลขประจำตัวเป็นใบเดียวใช้ทั้งห้อง เลือกนักเรียนไม่ได้',
                ]);
            }
            if ($kind === WorksheetPrint::KIND_ANSWER_SHEET && ! $shared) {
                $studentIds = self::roster($exam, $requestedStudents);
            }

            self::lockStructure($exam);
            $layout = $plan !== null ? self::layout($exam, $plan) : null;

            $print = WorksheetPrint::create([
                'assignment_id' => $exam->id,
                'kind' => $kind,
                'layout_version' => $layout?->version,
                'version_no' => $versionNo,
                'requested_by' => $teacher->id,
                'status' => WorksheetPrint::STATUS_QUEUED,
            ]);

            return [$print, self::jobs($print, $studentIds, $shared)];
        }, allowClosed: true);

        Bus::chain($jobs)->onQueue('pdf')->dispatch();

        return $print;
    }

    /**
     * @throws ApiException 409 answer_key_not_approved, 422 answer_key_incomplete / assignment_empty
     */
    private function assertPrintable(Assignment $exam, string $kind): void
    {
        $questions = ExamKeyCheck::questions($exam);
        if ($kind === WorksheetPrint::KIND_KEY_SHEET) {
            if ($questions->isEmpty()) {
                throw new ApiException('ข้อสอบนี้ยังไม่มีข้อ เพิ่มตอนและข้อก่อน', 'assignment_empty', 422);
            }

            return;
        }

        if (! $exam->isManualExam() && ! $exam->keyApproved()) {
            throw new ApiException('ต้องอนุมัติเฉลยก่อนพิมพ์ให้นักเรียน', 'answer_key_not_approved', 409);
        }
        if ($kind !== WorksheetPrint::KIND_EXAM_BOOKLET) {
            return;
        }
        if ($questions->isEmpty()) {
            throw new ApiException('ข้อสอบนี้ยังไม่มีข้อ เพิ่มข้อก่อนพิมพ์เล่ม', 'answer_key_incomplete', 422, ['questions' => ['ยังไม่มีข้อ']]);
        }
        $problems = ExamKeyCheck::bookletProblems($exam, $questions);
        if ($problems !== []) {
            throw new ApiException(
                'ข้อ '.implode(', ', array_column($problems, 'position')).' ยังพิมพ์ในเล่มไม่ได้ (ต้องอนุมัติข้อและมีโจทย์)',
                'answer_key_incomplete',
                422,
                ['questions' => ExamKeyCheck::messages($problems)],
            );
        }
    }

    /** Versions in step with the structure, then the lock (DESIGN §22.5, §22.6). Callers hold the row lock. */
    private static function lockStructure(Assignment $exam): void
    {
        if ($exam->structureLocked()) {
            return;
        }
        ExamVersions::sync($exam);
        $exam->structure_locked_at = now();
        $exam->save();
    }

    /** The current answer-sheet layout when the grid is unchanged, else a new version. */
    private static function layout(Assignment $exam, ExamSheetPlan $plan): Layout
    {
        $markers = ArucoMarkers::load();
        $current = $exam->currentLayout();
        if ($current !== null && $plan->toLayoutPages($exam->id, $current->version, $markers) == $current->pages) {
            return $current;
        }

        $version = (int) $exam->layouts()->max('version') + 1;
        $layout = $exam->layouts()->create([
            'version' => $version,
            'pages' => $plan->toLayoutPages($exam->id, $version, $markers),
        ]);
        $exam->current_layout_version = $version;
        $exam->save();

        return $layout;
    }

    /**
     * @param  list<int>  $studentIds
     * @return list<object>
     */
    private static function jobs(WorksheetPrint $print, array $studentIds, bool $shared = false): array
    {
        if ($print->kind === WorksheetPrint::KIND_EXAM_BOOKLET) {
            return [new RenderExamBookletJob($print->id)];
        }
        if ($print->kind === WorksheetPrint::KIND_KEY_SHEET || $shared) {
            return [new RenderAnswerSheetsJob($print->id, 0, []), new MergeWorksheetsJob($print->id, 1)];
        }

        $chunks = array_chunk($studentIds, (int) config('eduvision.exams.sheet_batch_size', 20));
        $jobs = [];
        foreach ($chunks as $index => $ids) {
            $jobs[] = new RenderAnswerSheetsJob($print->id, $index, $ids);
        }
        $jobs[] = new MergeWorksheetsJob($print->id, count($chunks));

        return $jobs;
    }

    /** @throws ValidationException errors.version_no */
    private static function versionNo(Assignment $exam, mixed $value): int
    {
        if ($value === null && $exam->version_count <= 1) {
            return 1;
        }
        $versionNo = filter_var($value, FILTER_VALIDATE_INT);
        if ($versionNo === false || $versionNo < 1 || $versionNo > $exam->version_count) {
            throw ValidationException::withMessages([
                'version_no' => $value === null ? 'เลือกชุดของเล่มที่จะพิมพ์' : 'ข้อสอบนี้มี '.$exam->version_count.' ชุด',
            ]);
        }
        if (! ExamVersion::query()->where('assignment_id', $exam->id)->where('version_no', $versionNo)->exists() && $exam->structureLocked()) {
            throw ValidationException::withMessages(['version_no' => 'ยังไม่มีชุดนี้']);
        }

        return $versionNo;
    }

    /**
     * @return list<int>|null null = the whole roster
     *
     * @throws ValidationException errors.student_ids
     */
    private static function studentIds(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }
        if (! is_array($value) || ! array_is_list($value) || $value === []) {
            throw ValidationException::withMessages(['student_ids' => 'เลือกนักเรียนอย่างน้อย 1 คน']);
        }
        $ids = [];
        foreach ($value as $i => $id) {
            $int = filter_var($id, FILTER_VALIDATE_INT);
            if ($int === false || $int < 1) {
                throw ValidationException::withMessages(["student_ids.{$i}" => 'ไม่พบนักเรียนคนนี้ในห้อง']);
            }
            $ids[] = $int;
        }

        return array_values(array_unique($ids));
    }

    /**
     * Student ids to print, ordered by เลขที่.
     *
     * @param  list<int>|null  $requested
     * @return list<int>
     *
     * @throws ApiException 422 classroom_empty
     * @throws ValidationException errors.student_ids.N
     */
    private static function roster(Assignment $exam, ?array $requested): array
    {
        $enrolled = $exam->classroom->students()->pluck('users.id')->map(fn ($id) => (int) $id)->values()->all();
        if ($requested === null) {
            if ($enrolled === []) {
                throw new ApiException('ห้องนี้ยังไม่มีนักเรียน', 'classroom_empty', 422);
            }

            return $enrolled;
        }
        foreach ($requested as $i => $id) {
            if (! in_array($id, $enrolled, true)) {
                throw ValidationException::withMessages(["student_ids.{$i}" => 'ไม่พบนักเรียนคนนี้ในห้อง']);
            }
        }

        return array_values(array_filter($enrolled, fn (int $id) => in_array($id, $requested, true)));
    }
}
