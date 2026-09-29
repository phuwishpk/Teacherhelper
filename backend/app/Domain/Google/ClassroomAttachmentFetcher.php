<?php

namespace App\Domain\Google;

use App\Domain\Pages\PageFiles;
use App\Domain\Pages\PdfPageCounter;
use App\Domain\Pages\WholePageSubmissions;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GoogleAccount;
use App\Models\SubmissionPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Server-side ingestion of a Classroom hand-in (DESIGN §19.4, replacing the
 * phone download of §18.1): the Drive files of one `new`
 * classroom_submission_imports row are checked and downloaded with the
 * Google account of the teacher who linked the course (drive.readonly), then
 * handed to WholePageSubmissions, which stores them and grades them from the
 * whole page (no QR, no marker).
 *
 * - whose work: the student matched to the submitter's Google account
 *   (classroom_students.google_user_id); an unmatched submitter waits (the
 *   row stays `new` with a note until the roster is matched);
 * - what is accepted: JPEG, PNG, WebP, HEIC/HEIF and PDF, at most
 *   SUBMISSION_MAX_FILE_MB per file and SUBMISSION_MAX_PAGES pages in all
 *   (PDF pages counted with PdfPageCounter). Anything else makes the row
 *   `unsupported` with the reason in Thai (last_error); nothing is stored;
 * - a hand-in answering a retake request (retake_reason kept by the sync)
 *   is graded at once; any other new hand-in of a graded submission waits
 *   for the teacher (§19.4);
 * - before the teacher approved the answer key the files are stored but
 *   not graded: the row becomes `waiting_key` until approval releases it
 *   (ReleaseWaitingSubmissionsJob, §19.5).
 *
 * Google errors: unreachable -> thrown (the job retries); anything else
 * (file gone, access denied, reconnect needed) -> noted on the row, which
 * stays `new` for the next sync.
 */
final class ClassroomAttachmentFetcher
{
    public const OUTCOME_IMPORTED = 'imported';

    public const OUTCOME_UNSUPPORTED = 'unsupported';

    public const OUTCOME_WAITING = 'waiting';

    public const OUTCOME_SKIPPED = 'skipped';

    public const UNMATCHED = 'ผู้ส่งยังไม่ได้จับคู่กับนักเรียนในห้อง จับคู่ที่หน้าห้องเรียนแล้วกด "ดึงงานที่ส่ง" อีกครั้ง';

    public function __construct(
        private readonly WholePageSubmissions $submissions,
        private readonly GoogleAccessTokens $tokens,
    ) {}

    /**
     * @throws GoogleApiException when Google is unreachable (worth a retry)
     */
    public function fetch(int $importId): string
    {
        $import = ClassroomSubmissionImport::query()->with('assignment.classroom.googleLink')->find($importId);
        $assignment = $import?->assignment;
        $link = $assignment?->classroom?->googleLink;
        if ($import === null || $assignment === null || $link === null
            || $import->state !== ClassroomSubmissionImport::STATE_NEW || $assignment->isClosed()) {
            return self::OUTCOME_SKIPPED;
        }

        $studentId = ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->where('google_user_id', $import->google_user_id)
            ->value('student_id');
        if ($studentId === null) {
            return $this->wait($import, self::UNMATCHED);
        }

        $account = GoogleAccount::query()->find($link->owner_user_id);
        if ($account === null || $account->needsReconnect()) {
            return $this->wait($import, 'ต้องเชื่อมบัญชี Google ของครูใหม่ก่อน จึงจะดึงไฟล์งานที่ส่งได้');
        }
        $api = GoogleApi::forAccount($account, $this->tokens);

        try {
            $files = $this->download($api, $import);
        } catch (GoogleApiException $e) {
            if ($e->isTransient()) {
                throw $e;
            }
            $message = $e->kind === GoogleApiException::NOT_FOUND
                ? 'ไม่พบไฟล์งานที่ส่งใน Google Drive แล้ว (นักเรียนอาจลบไฟล์)'
                : 'ดึงไฟล์จาก Google Drive ไม่ได้ ('.$e->kind.')';
            Log::warning('google.attachments_failed', ['import_id' => $import->id, 'kind' => $e->kind]);

            return $this->wait($import, $message);
        }
        if (is_string($files)) {
            $import->state = ClassroomSubmissionImport::STATE_UNSUPPORTED;
            $import->last_error = Str::limit($files, 250);
            $import->save();
            Log::info('google.attachments_unsupported', ['import_id' => $import->id]);

            return self::OUTCOME_UNSUPPORTED;
        }

        $retake = $import->retake_reason !== null;
        $received = $this->submissions->receive($assignment, (int) $studentId, $files, SubmissionPage::SOURCE_CLASSROOM, [
            'google_submission_id' => $import->google_submission_id,
            'submitted_at' => self::time($import->google_update_time),
            'late' => $import->late,
        ], $retake);

        // No approved key yet: the files are kept and graded after approval (§19.5).
        $import->state = $received['waiting_key'] ? ClassroomSubmissionImport::STATE_WAITING_KEY : ClassroomSubmissionImport::STATE_IMPORTED;
        $import->student_id = (int) $studentId;
        $import->retake_reason = null;
        $import->last_error = null;
        $import->save();

        return self::OUTCOME_IMPORTED;
    }

    /**
     * The checked files, or the Thai reason they cannot be graded.
     *
     * @return list<array{bytes: string, mime_type: string, page_count: int, drive_file_id: string}>|string
     */
    private function download(GoogleApi $api, ClassroomSubmissionImport $import): array|string
    {
        $maxBytes = PageFiles::maxBytes();
        $maxMb = (int) config('eduvision.submissions.max_file_mb');
        $maxPages = PageFiles::maxPages();
        $files = [];
        $pages = 0;
        foreach ($import->attachments ?? [] as $attachment) {
            $id = (string) ($attachment['drive_file_id'] ?? '');
            $title = (string) ($attachment['title'] ?? '');
            if ($id === '') {
                continue;
            }
            $meta = $api->driveFile($id);
            $name = $meta['name'] !== '' ? $meta['name'] : $title;
            if (str_starts_with($meta['mime_type'], 'application/vnd.google-apps.')) {
                return "ไฟล์ \"{$name}\" เป็น Google Docs/Sheets/Slides ซึ่งตรวจไม่ได้ ให้บันทึกเป็น PDF หรือถ่ายรูปแล้วส่งใหม่";
            }
            $type = PageFiles::acceptedType($meta['mime_type'], $name);
            if ($type === null) {
                return "ไฟล์ \"{$name}\" เป็นชนิดที่ตรวจไม่ได้ ส่งเป็นรูป (JPEG, PNG, WebP, HEIC) หรือ PDF";
            }
            if ($meta['size'] !== null && $meta['size'] > $maxBytes) {
                return "ไฟล์ \"{$name}\" ใหญ่เกิน {$maxMb} MB";
            }

            $bytes = $api->downloadDriveFile($id);
            if (strlen($bytes) > $maxBytes) {
                return "ไฟล์ \"{$name}\" ใหญ่เกิน {$maxMb} MB";
            }
            if ($bytes === '') {
                return "ไฟล์ \"{$name}\" ว่างเปล่า";
            }
            $count = 1;
            if ($type === 'application/pdf') {
                $count = PdfPageCounter::count($bytes);
                if ($count === 0) {
                    return "อ่านไฟล์ PDF \"{$name}\" ไม่ได้ ส่งเป็นรูป หรือบันทึกเป็น PDF ใหม่";
                }
            }
            $pages += $count;
            if ($pages > $maxPages) {
                return "งานนี้มีมากกว่า {$maxPages} หน้า ตรวจได้ไม่เกิน {$maxPages} หน้าต่อการส่ง";
            }
            $files[] = ['bytes' => $bytes, 'mime_type' => $type, 'page_count' => $count, 'drive_file_id' => $id];
        }
        if ($files === []) {
            return 'งานที่ส่งไม่มีไฟล์ที่ตรวจได้';
        }

        return $files;
    }

    private function wait(ClassroomSubmissionImport $import, string $message): string
    {
        $import->last_error = Str::limit($message, 250);
        $import->save();

        return self::OUTCOME_WAITING;
    }

    private static function time(string $value): ?Carbon
    {
        if ($value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
