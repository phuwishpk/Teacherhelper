<?php

namespace App\Domain\Scans;

use App\Domain\Worksheets\QrSigner;
use App\Domain\Worksheets\QrSigningKeyMissing;
use App\Domain\Worksheets\WorksheetQr;
use App\Exceptions\ApiException;
use App\Jobs\GradeScanJob;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\Layout;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use Throwable;

/**
 * POST /scans and POST /scans/{id}/confirm-replace (DESIGN §9.4).
 *
 * Checks, in order: known client_scan_id → 200 replay; QR signature
 * (422 qr_invalid); the teacher owns the assignment's classroom (403); the
 * layout version exists (422 layout_unknown); the page is in that layout and
 * the regions are that page's (422 page_mismatch); the student is enrolled
 * (422 student_unknown, also for the anonymous spare sheet of §18.3 when it
 * comes from the camera); then the images (422 validation_failed, or
 * 503 too_many_files when PHP dropped files past max_file_uploads).
 *
 * Duplicate rule, keyed by (assignment, student, page):
 * - submission not published: the new scan is `active`, earlier scans of the
 *   page become `superseded` and the page's responses are re-graded;
 * - submission published: the new scan waits as `pending_confirm` (its crops
 *   and readings stashed on the private disk) until the teacher calls
 *   confirm-replace; the published responses stay untouched until then.
 *
 * All changes to one submission run under its row lock, so pages of the same
 * student uploaded at the same moment apply one after the other.
 */
final class ScanIngestor
{
    public function __construct(private readonly ResponseWriter $writer) {}

    public function ingest(User $user, Request $request): ScanOutcome
    {
        $clientScanId = ScanMeta::clientScanIdFrom($request);
        if ($clientScanId !== null && ($replay = $this->replay($user, $clientScanId)) !== null) {
            return $replay;
        }

        $meta = ScanMeta::fromRequest($request);
        $qr = $this->verifyQr($meta->qr);

        $assignment = Assignment::query()->with('classroom')->find($qr->assignmentId);
        if ($assignment === null) {
            throw new ApiException('ไม่พบการบ้านของใบงานนี้ในระบบ', 'qr_invalid', 422);
        }
        Gate::forUser($user)->authorize('scan', $assignment);

        $layout = $assignment->layouts()->where('version', $qr->layoutVersion)->first();
        if ($layout === null) {
            throw new ApiException("ไม่พบ layout เวอร์ชัน {$qr->layoutVersion} ของการบ้านนี้", 'layout_unknown', 422);
        }

        $page = LayoutPageMatcher::page($layout, $qr->page);
        if ($page === null) {
            throw new ApiException(
                "การบ้านนี้ (layout เวอร์ชัน {$layout->version}) มี {$layout->pageCount()} หน้า ไม่มีหน้า {$qr->page}",
                'page_mismatch',
                422,
            );
        }

        $this->assertStudentEnrolled($qr, $assignment);
        $matched = LayoutPageMatcher::match($page, $meta->regions, $assignment);
        $files = $this->validatedFiles($request, $meta, $page);

        try {
            [$scan, $queued, $stalePending] = $this->store($user, $assignment, $qr, $meta, $matched, $files);
        } catch (UniqueConstraintViolationException $e) {
            // The same client_scan_id raced in on a parallel request; answer as a retry.
            $replay = $this->replay($user, $meta->clientScanId);
            if ($replay === null) {
                throw $e;
            }

            return $replay;
        }

        $this->afterCommit($assignment, $stalePending, $scan, $queued);

        Log::info('scans.stored', [
            'scan_id' => $scan->id,
            'submission_id' => $scan->submission_id,
            'page' => $scan->page_no,
            'state' => $scan->state,
            'queued' => $queued,
        ]);

        return ScanOutcome::created($scan);
    }

    /**
     * The teacher accepts a rescan of a published page: the pending scan
     * becomes active, the page's responses take its crops and readings, a
     * `rescan` score_event records every score that was replaced, and the
     * submission goes back to grading/review. Calling it again for a scan
     * that is already active answers the same (idempotent).
     */
    public function confirmReplace(User $user, Scan $scan): ScanOutcome
    {
        $scan->loadMissing('submission.assignment.classroom');
        Gate::forUser($user)->authorize('confirmReplace', $scan);
        $assignment = $scan->submission->assignment;
        $pendingDirectory = ScanFiles::pendingDirectory($assignment->school_id, $assignment->id, $scan->id);
        $swap = new CropSwap(ScanFiles::replacedDirectory($assignment->school_id, $assignment->id, $scan->id));

        try {
            [$scan, $queued, $stalePending, $confirmed] = DB::transaction(
                fn () => $this->applyConfirmation($user, $scan, $assignment, $pendingDirectory, $swap),
            );
        } catch (Throwable $e) {
            $swap->rollback();

            throw $e;
        }
        $swap->commit();

        if ($confirmed) {
            ScanFiles::disk()->deleteDirectory($pendingDirectory);
            $this->afterCommit($assignment, $stalePending, $scan, $queued);
            Log::info('scans.rescan_confirmed', ['scan_id' => $scan->id, 'submission_id' => $scan->submission_id, 'queued' => $queued]);
        }

        return new ScanOutcome($scan, 200);
    }

    /**
     * The transactional part of confirmReplace().
     *
     * @return array{0: Scan, 1: int, 2: list<int>, 3: bool} scan, queued, stale pending ids, confirmed now
     */
    private function applyConfirmation(User $user, Scan $scan, Assignment $assignment, string $pendingDirectory, CropSwap $swap): array
    {
        $submission = Submission::query()->lockForUpdate()->findOrFail($scan->submission_id);
        $scan = Scan::query()->lockForUpdate()->findOrFail($scan->id);

        if ($scan->isActive()) {
            return [$scan, 0, [], false];
        }
        if ($scan->isSuperseded()) {
            $newer = Scan::query()
                ->where('submission_id', $scan->submission_id)
                ->where('page_no', $scan->page_no)
                ->where('id', '>', $scan->id)
                ->exists();
            if (! $newer) {
                // Expired by eduvision:purge-images (ScanRetention) before the teacher decided.
                throw new ApiException('สแกนนี้รอการยืนยันนานเกินไปจนไฟล์ถูกลบแล้ว กรุณาสแกนหน้านี้ใหม่', 'scan_files_missing', 409);
            }

            throw new ApiException('มีการสแกนหน้านี้ใหม่กว่านี้แล้ว ใช้สแกนนี้แทนไม่ได้', 'scan_superseded', 409);
        }

        $regions = $this->readPending($pendingDirectory);
        $layout = $assignment->layouts()->where('version', $scan->layout_version)->first();
        $page = $layout instanceof Layout ? LayoutPageMatcher::page($layout, $scan->page_no) : null;
        if ($regions === null || $page === null) {
            throw new ApiException('ไม่พบไฟล์ของสแกนนี้แล้ว กรุณาสแกนหน้านี้ใหม่', 'scan_files_missing', 409);
        }
        $matched = LayoutPageMatcher::match($page, $regions, $assignment, strict: false);

        $stalePending = $this->supersedeOthers($submission, $scan);
        $scan->state = Scan::STATE_ACTIVE;
        $scan->save();

        $queued = $this->writer->write($submission, $scan, $assignment, $matched, new PendingCropSource($pendingDirectory), $user, $swap);
        SubmissionStatus::refresh($submission, reopen: true);

        return [$scan, $queued, $stalePending, true];
    }

    private function replay(User $user, string $clientScanId): ?ScanOutcome
    {
        $scan = Scan::query()->with('submission.assignment.classroom')->where('client_scan_id', $clientScanId)->first();
        if ($scan === null) {
            return null;
        }
        Gate::forUser($user)->authorize('view', $scan);

        return ScanOutcome::replayed($scan);
    }

    private function verifyQr(string $payload): WorksheetQr
    {
        try {
            $signer = app(QrSigner::class);
        } catch (QrSigningKeyMissing) {
            throw new ApiException(
                'ยังรับสแกนไม่ได้ เพราะเซิร์ฟเวอร์ยังไม่ได้ตั้งค่า QR_SIGNING_KEY กรุณาแจ้งผู้ดูแลระบบ',
                'qr_key_missing',
                503,
            );
        }

        $qr = $signer->verify($payload);
        if ($qr === null) {
            throw new ApiException('QR ของใบงานไม่ถูกต้อง หรือไม่ได้พิมพ์จากระบบนี้', 'qr_invalid', 422);
        }

        return $qr;
    }

    private function assertStudentEnrolled(WorksheetQr $qr, Assignment $assignment): void
    {
        if ($qr->isAnonymous()) {
            // DESIGN §18.3: the anonymous spare sheet only arrives through Google Classroom.
            throw new ApiException(
                'ใบงานสำรองที่ไม่ระบุชื่อต้องรับผ่าน Google Classroom สแกนด้วยกล้องไม่ได้',
                'student_unknown',
                422,
            );
        }

        $enrolled = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->where('student_id', $qr->studentId)
            ->exists();
        if (! $enrolled) {
            throw new ApiException('ไม่พบนักเรียนของใบงานนี้ในห้องเรียน', 'student_unknown', 422);
        }
    }

    /**
     * The page image and every crop the matched regions need, as WebP within
     * the size limits of config('eduvision.scans').
     *
     * @param  array<string, mixed>  $page
     * @return array<string, UploadedFile>
     */
    private function validatedFiles(Request $request, ScanMeta $meta, array $page): array
    {
        $pageKb = (int) config('eduvision.scans.max_page_kb');
        $cropKb = (int) config('eduvision.scans.max_crop_kb');
        $finals = [];
        foreach ($page['regions'] ?? [] as $layoutRegion) {
            $finals[(string) $layoutRegion['region_id']] = isset($layoutRegion['final_answer']);
        }

        $rules = [ScanMeta::PAGE_FIELD => ['required', 'file', 'mimes:webp', 'max:'.$pageKb]];
        $messages = [
            ScanMeta::PAGE_FIELD.'.required' => 'ไม่มีภาพหน้ากระดาษ (page)',
            ScanMeta::PAGE_FIELD.'.file' => 'ภาพหน้ากระดาษ (page) ต้องส่งเป็นไฟล์',
            ScanMeta::PAGE_FIELD.'.uploaded' => 'อัปโหลดภาพหน้ากระดาษไม่สำเร็จ (อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้)',
            ScanMeta::PAGE_FIELD.'.mimes' => 'ภาพหน้ากระดาษต้องเป็น WebP',
            ScanMeta::PAGE_FIELD.'.max' => "ภาพหน้ากระดาษใหญ่เกิน {$pageKb} KB",
        ];
        foreach ($meta->regions as $region) {
            $fields = [$region->file];
            if (($finals[$region->regionId] ?? false) && $region->finalFile !== null) {
                $fields[] = $region->finalFile;
            }
            foreach ($fields as $field) {
                $rules[$field] = ['required', 'file', 'mimes:webp', 'max:'.$cropKb];
                $messages["{$field}.required"] = "ไม่มีไฟล์ {$field}";
                $messages["{$field}.file"] = "{$field} ต้องส่งเป็นไฟล์";
                $messages["{$field}.uploaded"] = "อัปโหลดไฟล์ {$field} ไม่สำเร็จ (อาจใหญ่เกินที่เซิร์ฟเวอร์รับได้)";
                $messages["{$field}.mimes"] = "ไฟล์ {$field} ต้องเป็นภาพ WebP";
                $messages["{$field}.max"] = "ไฟล์ {$field} ใหญ่เกิน {$cropKb} KB";
            }
        }

        $uploaded = $request->allFiles();
        self::assertUploadNotTruncated($uploaded, array_keys($rules), (int) ini_get('max_file_uploads'));

        $validator = Validator::make($uploaded, $rules, $messages);
        $validator->validate();

        $files = [];
        foreach (array_keys($rules) as $field) {
            $files[$field] = $request->file($field);
        }

        return $files;
    }

    /**
     * PHP silently drops every file past max_file_uploads. Say so, instead of
     * reporting the dropped crops as "missing". The fix is a server setting,
     * so the answer is 503 (retryable): the app keeps the scan and sends it
     * again once the administrator has raised the limit, whereas a 4xx would
     * make the app give the scan up for good.
     *
     * @param  array<string, mixed>  $uploaded
     * @param  list<string>  $needed
     *
     * @throws ApiException 503 too_many_files
     */
    public static function assertUploadNotTruncated(array $uploaded, array $needed, int $limit): void
    {
        $received = count($uploaded, COUNT_RECURSIVE) - count(array_filter($uploaded, 'is_array'));
        if ($limit <= 0 || $received < $limit || count($needed) <= $limit) {
            return;
        }

        Log::warning('scans.upload_truncated', ['max_file_uploads' => $limit, 'needed' => count($needed)]);

        throw new ApiException(
            "เซิร์ฟเวอร์รับไฟล์ได้ครั้งละ {$limit} ไฟล์ แต่หน้านี้มี ".count($needed).' ไฟล์ แจ้งผู้ดูแลให้เพิ่มค่า max_file_uploads ของ PHP แอปจะส่งสแกนนี้ใหม่อัตโนมัติ',
            'too_many_files',
            503,
        );
    }

    /**
     * @param  list<MatchedRegion>  $matched
     * @param  array<string, UploadedFile>  $files
     * @return array{0: Scan, 1: int, 2: list<int>}
     */
    private function store(User $user, Assignment $assignment, WorksheetQr $qr, ScanMeta $meta, array $matched, array $files): array
    {
        // Outside the transaction: a concurrent first scan of the same student
        // may create the row first, and createOrFirst then reads the committed one.
        $submissionId = Submission::query()->createOrFirst([
            'assignment_id' => $assignment->id,
            'student_id' => $qr->studentId,
        ])->id;

        // Files written so far, undone when the transaction or its commit fails.
        $written = [];
        $swap = null;
        try {
            $result = DB::transaction(function () use ($user, $assignment, $qr, $meta, $matched, $files, $submissionId, &$written, &$swap) {
                $submission = Submission::query()->lockForUpdate()->findOrFail($submissionId);
                $published = $submission->isPublished();

                $scan = Scan::create([
                    'client_scan_id' => $meta->clientScanId,
                    'submission_id' => $submission->id,
                    'page_no' => $qr->page,
                    'layout_version' => $qr->layoutVersion,
                    'uploaded_by' => $user->id,
                    'scanned_at' => $meta->scannedAt,
                    'blur_score' => $meta->blurScore,
                    'state' => $published ? Scan::STATE_PENDING_CONFIRM : Scan::STATE_ACTIVE,
                ]);
                $pagePath = ScanFiles::pagePath($assignment->school_id, $assignment->id, $scan->id);
                $scan->page_image_path = $pagePath;
                $scan->save();

                $written[] = $pagePath;
                UploadedCropSource::put($files[ScanMeta::PAGE_FIELD], $pagePath);

                if ($published) {
                    // Only an older rescan still waiting for the teacher is replaced;
                    // the published responses stay as they are until confirm-replace.
                    $stalePending = $this->supersedeOthers($submission, $scan, [Scan::STATE_PENDING_CONFIRM]);
                    $directory = ScanFiles::pendingDirectory($assignment->school_id, $assignment->id, $scan->id);
                    $written[] = $directory;
                    $this->stashPending($directory, $matched, $files);
                    $queued = 0;
                } else {
                    $stalePending = $this->supersedeOthers($submission, $scan);
                    $swap = new CropSwap(ScanFiles::replacedDirectory($assignment->school_id, $assignment->id, $scan->id));
                    $queued = $this->writer->write($submission, $scan, $assignment, $matched, new UploadedCropSource($files), $user, $swap);
                    SubmissionStatus::refresh($submission);
                }

                return [$scan, $queued, $stalePending];
            });
        } catch (Throwable $e) {
            $swap?->rollback();
            $disk = ScanFiles::disk();
            foreach ($written as $path) {
                str_ends_with($path, '.webp') ? $disk->delete($path) : $disk->deleteDirectory($path);
            }

            throw $e;
        }
        $swap?->commit();

        return $result;
    }

    /**
     * Marks the other scans of the same page superseded.
     *
     * @param  list<string>  $states
     * @return list<int> ids of superseded scans that were pending_confirm (their stash can go)
     */
    private function supersedeOthers(Submission $submission, Scan $scan, array $states = [Scan::STATE_ACTIVE, Scan::STATE_PENDING_CONFIRM]): array
    {
        $others = Scan::query()
            ->where('submission_id', $submission->id)
            ->where('page_no', $scan->page_no)
            ->whereKeyNot($scan->id)
            ->whereIn('state', $states)
            ->get();

        $pending = [];
        foreach ($others as $other) {
            if ($other->isPendingConfirm()) {
                $pending[] = $other->id;
            }
            $other->state = Scan::STATE_SUPERSEDED;
            $other->save();
        }

        return $pending;
    }

    /**
     * @param  list<MatchedRegion>  $matched
     * @param  array<string, UploadedFile>  $files
     */
    private function stashPending(string $directory, array $matched, array $files): void
    {
        $regions = [];
        foreach ($matched as $item) {
            $region = $item->region;
            UploadedCropSource::put($files[$region->file], ScanFiles::pendingCrop($directory, $region->regionId));
            if ($item->hasFinalAnswer() && $region->finalFile !== null) {
                UploadedCropSource::put($files[$region->finalFile], ScanFiles::pendingCrop($directory, $region->regionId, true));
            }
            $regions[] = $region->toArray();
        }

        $json = json_encode(['regions' => $regions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (! ScanFiles::disk()->put(ScanFiles::pendingMeta($directory), $json)) {
            throw new RuntimeException("Could not write {$directory}/regions.json");
        }
    }

    /**
     * @return list<ScanRegion>|null
     */
    private function readPending(string $directory): ?array
    {
        $disk = ScanFiles::disk();
        $path = ScanFiles::pendingMeta($directory);
        if (! $disk->exists($path)) {
            return null;
        }

        $data = json_decode((string) $disk->get($path), true);
        if (! is_array($data) || ! is_array($data['regions'] ?? null)) {
            return null;
        }

        return array_map(fn (array $r) => ScanRegion::fromArray($r), array_values($data['regions']));
    }

    /**
     * @param  list<int>  $stalePending
     */
    private function afterCommit(Assignment $assignment, array $stalePending, Scan $scan, int $queued): void
    {
        foreach ($stalePending as $scanId) {
            ScanFiles::disk()->deleteDirectory(ScanFiles::pendingDirectory($assignment->school_id, $assignment->id, $scanId));
        }

        if ($queued > 0) {
            GradeScanJob::dispatch($scan->id);
        }
    }
}
