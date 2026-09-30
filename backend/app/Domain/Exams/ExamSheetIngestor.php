<?php

namespace App\Domain\Exams;

use App\Domain\Scans\ScanFiles;
use App\Domain\Scans\ScanIngestor;
use App\Domain\Scans\SubmissionStatus;
use App\Domain\Scans\UploadedCropSource;
use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Domain\Worksheets\WorksheetQr;
use App\Events\SubmissionReopened;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\ExamSheetRead;
use App\Models\Layout;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Throwable;

/**
 * POST /exam-sheets (DESIGN §22.11): one scanned answer-sheet page,
 * multipart `meta` {client_scan_id, qr, scanned_at, blur_score,
 * version_fill?, rows, digits?, device_score?} + `page` (the warped page,
 * WebP). Scored by code in the request: no queue, no Gemini.
 *
 * Checks in order: a known client_scan_id answers 200 with what the server
 * holds now; the EVX1 QR signature (422 qr_invalid; the teacher's key sheet,
 * student 0, is qr_invalid here); the teacher owns the exam (403); an `app`
 * exam (422 exam_manual_grading); the QR's layout_version is the exam's
 * current one (422 layout_unknown: after "ปลดล็อกโครงสร้าง" the versions
 * were shuffled again, so older sheets cannot be scored); the page exists
 * (422 page_mismatch) and the readings are that page's (422 page_mismatch);
 * the student is in the classroom (422 student_unknown); the page image.
 *
 * Rescans follow §9.4 keyed by (exam, student, page): before publishing the
 * new page replaces the old one at once; after publishing it waits as
 * pending_confirm until POST /scans/{id}/confirm-replace (confirmReplace()).
 *
 * Answer: 201 (200 replay, 202 pending_confirm) {scan_id, submission_id,
 * state, page_no, page_count, version_no, score, max_score, doubts[],
 * needs_version}. score / max_score are this page's by the server's code;
 * null while the version is unknown. The phone's own score is kept in
 * exam_sheet_reads.device_score for comparison only.
 */
final class ExamSheetIngestor
{
    public function __construct(private readonly ExamSheetGrader $grader) {}

    /**
     * @return array{0: array<string, mixed>, 1: int} body, status
     */
    public function ingest(User $user, Request $request): array
    {
        $meta = self::decodeMeta($request);
        $clientScanId = self::clientScanId($meta);
        if ($clientScanId !== null && ($replay = $this->replay($user, $clientScanId)) !== null) {
            return $replay;
        }

        $validated = self::validateMeta($meta);
        $qr = self::verifyQr($validated['qr']);
        if ($qr->isAnonymous()) {
            throw new ApiException('นี่คือกระดาษเฉลยของครู สแกนในหน้า "สแกนกระดาษเฉลย" ของข้อสอบแทน', 'qr_invalid', 422);
        }
        $exam = Assignment::query()->with('classroom')->find($qr->assignmentId);
        if ($exam === null || ! $exam->isExam()) {
            throw new ApiException('ไม่พบข้อสอบของกระดาษคำตอบนี้ในระบบ', 'qr_invalid', 422);
        }
        Gate::forUser($user)->authorize('scan', $exam);
        if ($exam->isManualExam()) {
            throw new ApiException('ข้อสอบนี้ครูตรวจเอง ไม่รับกระดาษคำตอบ', 'exam_manual_grading', 422);
        }
        [$layout, $page] = self::currentPage($exam, $qr);
        if (! ClassroomStudent::query()->where('classroom_id', $exam->classroom_id)->where('student_id', $qr->studentId)->exists()) {
            throw new ApiException('ไม่พบนักเรียนของกระดาษคำตอบนี้ในห้องเรียน', 'student_unknown', 422);
        }
        $reading = ExamSheetReading::against($validated['version_fill'] ?? null, $validated['rows'], $validated['digits'] ?? null, $page);
        $file = self::validatedPage($request);

        try {
            $scan = $this->store($user, $exam, $qr, $validated, $reading, $file);
        } catch (UniqueConstraintViolationException $e) {
            $replay = $this->replay($user, (string) $validated['client_scan_id']);
            if ($replay === null) {
                throw $e;
            }

            return $replay;
        }

        Log::info('exam_sheets.stored', ['scan_id' => $scan->id, 'submission_id' => $scan->submission_id, 'page' => $scan->page_no, 'state' => $scan->state]);

        return [self::body($exam, $scan, $layout), $scan->isPendingConfirm() ? 202 : 201];
    }

    /**
     * The teacher accepts a rescan of a published exam page (called by
     * ScanIngestor::confirmReplace for scans of an exam): the waiting page
     * becomes active, the submission goes back to review and is scored again.
     */
    public function confirmReplace(User $user, Scan $scan): Scan
    {
        return DB::transaction(function () use ($user, $scan) {
            $submission = Submission::query()->lockForUpdate()->findOrFail($scan->submission_id);
            $scan = Scan::query()->lockForUpdate()->findOrFail($scan->id);
            if ($scan->isActive()) {
                return $scan;
            }
            if ($scan->isSuperseded()) {
                throw new ApiException('มีการสแกนหน้านี้ใหม่กว่านี้แล้ว ใช้สแกนนี้แทนไม่ได้', 'scan_superseded', 409);
            }
            $exam = Assignment::query()->findOrFail($submission->assignment_id);

            self::supersedeOthers($submission, $scan, [Scan::STATE_ACTIVE, Scan::STATE_PENDING_CONFIRM]);
            $scan->state = Scan::STATE_ACTIVE;
            $scan->save();

            $wasPublished = $submission->isPublished();
            $this->grader->apply($submission, $exam, ExamScanKit::keys($exam), $user);
            self::refreshStatus($submission, $exam, reopen: true);
            if ($wasPublished) {
                SubmissionReopened::dispatch($submission->id, $submission->assignment_id, $submission->student_id);
            }

            return $scan;
        });
    }

    /**
     * Status of an exam submission (SubmissionStatus, then §22.11): a page
     * waiting for its version, or a missing page, keeps it in needs_review
     * even when every answer so far is reviewed, so it is never published
     * incomplete. Call under the submission row lock.
     */
    public static function refreshStatus(Submission $submission, Assignment $exam, bool $reopen = false): void
    {
        $status = SubmissionStatus::refresh($submission, $reopen);
        if ($status === Submission::STATUS_PUBLISHED) {
            return;
        }
        $active = Scan::query()->where('submission_id', $submission->id)->where('state', Scan::STATE_ACTIVE);
        $pages = (clone $active)->distinct()->pluck('page_no')->count();
        $waiting = ExamSheetRead::query()->whereIn('scan_id', (clone $active)->select('id'))->whereNull('version_no')->exists();
        $pageCount = $exam->currentLayout()?->pageCount() ?? 0;
        if ($pages > 0 && ($waiting || $pages < $pageCount) && in_array($status, [Submission::STATUS_AWAITING_SCAN, Submission::STATUS_REVIEWED], true)) {
            $submission->status = Submission::STATUS_NEEDS_REVIEW;
            $submission->save();
        }
    }

    /**
     * {scan_id, submission_id, state, page_no, page_count, version_no, score,
     * max_score, doubts, needs_version} of a stored page, scored now from
     * its reading (a pending_confirm page has no responses yet).
     *
     * @return array<string, mixed>
     */
    public static function body(Assignment $exam, Scan $scan, ?Layout $layout = null): array
    {
        $read = ExamSheetRead::query()->find($scan->id);
        $layout ??= $exam->layouts()->where('version', $scan->layout_version)->first();
        $body = [
            'scan_id' => $scan->id,
            'submission_id' => $scan->submission_id,
            'state' => $scan->state,
            'page_no' => $scan->page_no,
            'page_count' => $layout?->pageCount() ?? 0,
            'version_no' => null,
            'score' => null,
            'max_score' => null,
            'doubts' => [],
            'needs_version' => false,
        ];
        if (! $read instanceof ExamSheetRead) {
            return $body;
        }
        $pageOne = null;
        if ($scan->page_no > 1) {
            $pageOne = ExamSheetRead::query()
                ->whereIn('scan_id', Scan::query()->select('id')->where('submission_id', $scan->submission_id)->where('page_no', 1)->where('state', Scan::STATE_ACTIVE))
                ->value('version_no');
        }
        $evaluation = ExamSheetGrader::evaluate($exam, $scan, $read, $pageOne === null ? null : (int) $pageOne, ExamScanKit::keys($exam));

        return [
            ...$body,
            'version_no' => $evaluation['version_no'],
            'score' => $evaluation['score'],
            'max_score' => $evaluation['max_score'],
            'doubts' => $evaluation['doubts'],
            'needs_version' => $evaluation['version_no'] === null,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: int}|null
     */
    private function replay(User $user, string $clientScanId): ?array
    {
        $scan = Scan::query()->with('submission.assignment.classroom')->where('client_scan_id', $clientScanId)->first();
        if ($scan === null) {
            return null;
        }
        Gate::forUser($user)->authorize('view', $scan);
        $exam = $scan->submission->assignment;
        if (! $exam->isExam()) {
            throw new ApiException('client_scan_id นี้เป็นของใบงาน ไม่ใช่กระดาษคำตอบ', 'validation_failed', 422);
        }

        return [self::body($exam, $scan), 200];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function store(User $user, Assignment $exam, WorksheetQr $qr, array $meta, ExamSheetReading $reading, UploadedFile $file): Scan
    {
        $submissionId = Submission::query()->createOrFirst([
            'assignment_id' => $exam->id,
            'student_id' => $qr->studentId,
        ])->id;

        $written = null;
        try {
            return DB::transaction(function () use ($user, $exam, $qr, $meta, $reading, $file, $submissionId, &$written) {
                $submission = Submission::query()->lockForUpdate()->findOrFail($submissionId);
                $published = $submission->isPublished();

                $scannedAt = CarbonImmutable::parse($meta['scanned_at'])->utc();
                $scan = Scan::create([
                    'client_scan_id' => strtolower((string) $meta['client_scan_id']),
                    'submission_id' => $submission->id,
                    'page_no' => $qr->page,
                    'layout_version' => $qr->layoutVersion,
                    'uploaded_by' => $user->id,
                    'scanned_at' => $scannedAt->isFuture() ? CarbonImmutable::now()->utc() : $scannedAt,
                    'blur_score' => (float) $meta['blur_score'],
                    'state' => $published ? Scan::STATE_PENDING_CONFIRM : Scan::STATE_ACTIVE,
                ]);
                $path = ScanFiles::pagePath($exam->school_id, $exam->id, $scan->id);
                $scan->page_image_path = $path;
                $scan->save();
                $written = $path;
                UploadedCropSource::put($file, $path);

                ExamSheetRead::create([
                    'scan_id' => $scan->id,
                    'assignment_id' => $exam->id,
                    'version_fill' => $reading->versionFill,
                    'rows_fill' => $reading->rows,
                    'digits_fill' => $reading->digits === [] ? null : $reading->digits,
                    'device_score' => isset($meta['device_score']) ? round((float) $meta['device_score'], 2) : null,
                ]);

                if ($published) {
                    // The published answers stay until the teacher confirms (§9.4).
                    self::supersedeOthers($submission, $scan, [Scan::STATE_PENDING_CONFIRM]);
                } else {
                    self::supersedeOthers($submission, $scan, [Scan::STATE_ACTIVE, Scan::STATE_PENDING_CONFIRM]);
                    $this->grader->apply($submission, $exam, ExamScanKit::keys($exam), $user);
                    self::refreshStatus($submission, $exam);
                }

                return $scan;
            });
        } catch (Throwable $e) {
            if ($written !== null) {
                ScanFiles::disk()->delete($written);
            }

            throw $e;
        }
    }

    /**
     * @param  list<string>  $states
     */
    private static function supersedeOthers(Submission $submission, Scan $scan, array $states): void
    {
        $others = Scan::query()
            ->where('submission_id', $submission->id)
            ->where('page_no', $scan->page_no)
            ->whereKeyNot($scan->id)
            ->whereIn('state', $states)
            ->get();
        foreach ($others as $other) {
            $other->state = Scan::STATE_SUPERSEDED;
            $other->save();
        }
    }

    /**
     * @return array{0: Layout, 1: array<string, mixed>}
     *
     * @throws ApiException 422 layout_unknown / page_mismatch
     */
    private static function currentPage(Assignment $exam, WorksheetQr $qr): array
    {
        $layout = $exam->currentLayout();
        if ($layout === null || $layout->version !== $qr->layoutVersion) {
            throw new ApiException(
                'กระดาษคำตอบนี้พิมพ์จากโครงสร้างข้อสอบเดิม (ก่อนปลดล็อก) ต้องพิมพ์กระดาษคำตอบใหม่',
                'layout_unknown',
                422,
            );
        }
        foreach ($layout->pages as $index => $candidate) {
            if ((int) ($candidate['page'] ?? $index + 1) === $qr->page) {
                return [$layout, $candidate];
            }
        }

        throw new ApiException("กระดาษคำตอบนี้มี {$layout->pageCount()} หน้า ไม่มีหน้า {$qr->page}", 'page_mismatch', 422);
    }

    public static function verifyQr(string $payload): WorksheetQr
    {
        try {
            $signer = app(QrSigner::class);
        } catch (QrSigningKeyMissing) {
            throw new ApiException(QrSigningKeyMissing::USER_MESSAGE, 'qr_key_missing', 503);
        }
        $qr = $signer->verifyExamSheet(trim($payload));
        if ($qr === null) {
            throw new ApiException('QR ของกระดาษคำตอบไม่ถูกต้อง หรือไม่ได้พิมพ์จากระบบนี้', 'qr_invalid', 422);
        }

        return $qr;
    }

    private static function validatedPage(Request $request): UploadedFile
    {
        $pageKb = (int) config('eduvision.scans.max_page_kb');
        ScanIngestor::assertUploadNotTruncated($request->allFiles(), ['page'], (int) ini_get('max_file_uploads'));
        Validator::make($request->allFiles(), ['page' => ['required', 'file', 'mimes:webp', 'max:'.$pageKb]], [
            'page.required' => 'ไม่มีภาพหน้ากระดาษ (page)',
            'page.file' => 'ภาพหน้ากระดาษ (page) ต้องส่งเป็นไฟล์',
            'page.uploaded' => 'อัปโหลดภาพหน้ากระดาษไม่สำเร็จ (อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้)',
            'page.mimes' => 'ภาพหน้ากระดาษต้องเป็น WebP',
            'page.max' => "ภาพหน้ากระดาษใหญ่เกิน {$pageKb} KB",
        ])->validate();

        /** @var UploadedFile $file */
        $file = $request->file('page');

        return $file;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function decodeMeta(Request $request): ?array
    {
        $raw = $request->input('meta');
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    private static function clientScanId(?array $meta): ?string
    {
        $id = $meta['client_scan_id'] ?? null;

        return is_string($id) && preg_match('/\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z/', $id) === 1
            ? strtolower($id)
            : null;
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function validateMeta(?array $meta): array
    {
        if ($meta === null) {
            throw ValidationException::withMessages(['meta' => ['ไม่มีข้อมูล meta ของกระดาษคำตอบ หรือไม่ใช่ JSON ที่ถูกต้อง']]);
        }

        $rules = [
            'client_scan_id' => ['required', 'string', 'uuid'],
            'qr' => ['required', 'string', 'max:128'],
            'scanned_at' => ['required', 'date', 'after:2020-01-01'],
            'blur_score' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'device_score' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:9999'],
            ...ExamSheetReading::rules(),
        ];
        $messages = [
            'client_scan_id.required' => 'ไม่มี client_scan_id',
            'client_scan_id.uuid' => 'client_scan_id ต้องเป็น UUID',
            'qr.required' => 'ไม่มีข้อความ QR ของกระดาษคำตอบ',
            'scanned_at.required' => 'ไม่มีเวลาที่สแกน',
            'scanned_at.date' => 'เวลาที่สแกนไม่ถูกต้อง',
            'scanned_at.after' => 'เวลาที่สแกนไม่ถูกต้อง',
            'blur_score.required' => 'ไม่มีค่าความคมชัดของภาพ (blur_score)',
            'string' => ':attribute ต้องเป็นข้อความ',
            'uuid' => ':attribute ต้องเป็น UUID',
            'date' => ':attribute ต้องเป็นวันเวลาที่ถูกต้อง',
            'after' => ':attribute ต้องเป็นวันเวลาที่ถูกต้อง',
            'min' => ':attribute ต้องไม่น้อยกว่า :min',
            ...ExamSheetReading::messages(),
        ];

        return Validator::make($meta, $rules, $messages)->validate();
    }
}
