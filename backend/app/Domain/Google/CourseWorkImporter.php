<?php

namespace App\Domain\Google;

use App\Domain\AnswerKeys\AnswerKeyResult;
use App\Domain\AnswerKeys\AnswerKeyService;
use App\Domain\Documents\SourceDocuments;
use App\Domain\Notifications\Notifier;
use App\Domain\Pages\PageFiles;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\GoogleAccount;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * courseWork the teacher created on the Classroom website becomes a mirror
 * assignment in the app (DESIGN §19.3, §19.5):
 *
 * - found by the cron sync (newCourseWork()): PUBLISHED, not created by this
 *   project (associatedWithDeveloper), of type ASSIGNMENT (question types are
 *   answered inside Classroom, there is nothing to photograph), created
 *   after the course was linked (older courseWork is not pulled in, so
 *   linking a course in the middle of a term does not start a Gemini draft
 *   for every old assignment), and not mirrored yet;
 * - import(): the mirror is `freeform`, `source = classroom_web`, `draft`,
 *   without subject until the teacher picks one when approving the key; its
 *   assignment_google_links row has origin `classroom_web` (no grade is ever
 *   pushed to it) and the Drive materials with whether they can be read
 *   (PDF or image within DOCUMENT_MAX_FILE_MB; Google Docs/Sheets/Slides
 *   cannot, the app says "บันทึกเป็น PDF แล้วแนบในแอป หรือพิมพ์เฉลยเอง");
 * - Gemini drafts questions, key and rubric at once (answer_key_draft,
 *   thinking medium) from the title, the instructions and the readable
 *   materials, stored as the linking teacher's documents so the school's
 *   read-once cache applies; nothing is drafted from a title alone, or
 *   without a usable Gemini key (the teacher drafts or types it later);
 * - the teacher gets "มีงานใหม่จาก Classroom รออนุมัติเฉลย" (FCM) and the
 *   assignment counts in GET /teacher/attention until the key is approved.
 *
 * Hand-ins that arrive before the approval wait as `waiting_key` (§19.5).
 */
final class CourseWorkImporter
{
    public const DEFAULT_TITLE = 'งานจาก Google Classroom';

    /** Characters of title + instructions sent to Gemini. */
    private const MAX_COURSEWORK_TEXT = 4000;

    public function __construct(
        private readonly GoogleAccessTokens $tokens,
        private readonly SourceDocuments $documents,
        private readonly AnswerKeyService $keys,
        private readonly Notifier $notifier,
    ) {}

    /**
     * courseWork of the linked course that should become a mirror.
     *
     * @return list<array<string, mixed>> raw CourseWork resources
     *
     * @throws GoogleApiException
     */
    public static function newCourseWork(GoogleApi $api, ClassroomGoogleLink $link): array
    {
        $known = AssignmentGoogleLink::query()
            ->whereIn('assignment_id', Assignment::query()->where('classroom_id', $link->classroom_id)->select('id'))
            ->pluck('course_work_id')
            ->map(fn ($id) => (string) $id)
            ->flip();

        $new = [];
        foreach ($api->courseWorks($link->course_id) as $work) {
            $id = (string) ($work['id'] ?? '');
            if ($id === '' || isset($known[$id]) || ($work['associatedWithDeveloper'] ?? false) === true) {
                continue;
            }
            if (($work['workType'] ?? 'ASSIGNMENT') !== 'ASSIGNMENT') {
                continue;
            }
            $created = self::time($work['creationTime'] ?? null);
            if ($created !== null && $link->linked_at !== null && $created->lt($link->linked_at)) {
                continue;
            }
            $new[] = $work;
        }

        return $new;
    }

    /**
     * @param  array<string, mixed>  $work  the CourseWork resource
     * @return Assignment|null the mirror, null when there is nothing (more) to import
     *
     * @throws GoogleApiException only transient ones (the job retries)
     */
    public function import(int $classroomId, array $work): ?Assignment
    {
        $classroom = Classroom::query()->with('googleLink')->find($classroomId);
        $link = $classroom?->googleLink;
        $courseWorkId = (string) ($work['id'] ?? '');
        if ($classroom === null || $link === null || $courseWorkId === '' || $this->mirrored($classroom, $courseWorkId)) {
            return null;
        }
        $owner = User::query()->find($link->owner_user_id);
        $account = GoogleAccount::query()->find($link->owner_user_id);
        if ($owner === null || $account === null || $account->needsReconnect()) {
            return null; // the next sync round tries again once the teacher reconnects
        }
        $api = GoogleApi::forAccount($account, $this->tokens);

        $materials = $this->materials($api, $work);

        $assignment = DB::transaction(function () use ($classroom, $link, $owner, $work, $courseWorkId, $materials) {
            // Serialises imports of the same classroom (the job is unique per courseWork only).
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
            if ($this->mirrored($classroom, $courseWorkId)) {
                return null;
            }
            $title = trim((string) ($work['title'] ?? ''));
            $assignment = Assignment::create([
                'school_id' => $classroom->school_id,
                'classroom_id' => $classroom->id,
                'subject_id' => null,
                'created_by' => $owner->id,
                'title' => Str::limit($title !== '' ? $title : self::DEFAULT_TITLE, 250, ''),
                'status' => Assignment::STATUS_DRAFT,
                'due_at' => self::dueAt($work),
                'mode' => Assignment::MODE_FREEFORM,
                'source' => Assignment::SOURCE_CLASSROOM_WEB,
            ]);
            $alternate = (string) ($work['alternateLink'] ?? '');
            AssignmentGoogleLink::create([
                'assignment_id' => $assignment->id,
                'course_work_id' => $courseWorkId,
                'alternate_link' => mb_substr($alternate !== '' ? $alternate : 'https://classroom.google.com/', 0, 512),
                'posted_by' => $link->owner_user_id,
                'posted_at' => self::time($work['creationTime'] ?? null) ?? now(),
                'origin' => AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB,
                'materials' => $materials,
            ]);

            return $assignment;
        });
        if ($assignment === null) {
            return null;
        }
        Log::info('google.coursework_imported', ['assignment_id' => $assignment->id, 'classroom_id' => $classroom->id, 'materials' => count($materials)]);

        $this->draftKey($api, $owner, $assignment, $work, $materials);

        try {
            $this->notifier->classroomWorkImported($assignment->load('classroom'));
        } catch (Throwable $e) {
            report($e);
        }

        return $assignment->refresh();
    }

    private function mirrored(Classroom $classroom, string $courseWorkId): bool
    {
        return AssignmentGoogleLink::query()
            ->where('course_work_id', $courseWorkId)
            ->whereIn('assignment_id', Assignment::query()->where('classroom_id', $classroom->id)->select('id'))
            ->exists();
    }

    /**
     * The Drive materials and whether the server can read them.
     *
     * @param  array<string, mixed>  $work
     * @return list<array{drive_file_id: string, title: string, mime_type: string, supported: bool}>
     *
     * @throws GoogleApiException when Google is unreachable
     */
    private function materials(GoogleApi $api, array $work): array
    {
        $maxBytes = (int) config('eduvision.documents.max_file_mb') * 1024 * 1024;
        $out = [];
        foreach (is_array($work['materials'] ?? null) ? $work['materials'] : [] as $material) {
            $file = is_array($material) ? ($material['driveFile']['driveFile'] ?? null) : null;
            if (! is_array($file) || ! is_string($file['id'] ?? null) || $file['id'] === '') {
                continue; // links, YouTube videos and forms are not documents
            }
            $title = mb_substr((string) ($file['title'] ?? ''), 0, 255);
            try {
                $meta = $api->driveFile($file['id']);
            } catch (GoogleApiException $e) {
                if ($e->isTransient()) {
                    throw $e;
                }
                $out[] = ['drive_file_id' => $file['id'], 'title' => $title, 'mime_type' => '', 'supported' => false];

                continue;
            }
            $name = $meta['name'] !== '' ? $meta['name'] : $title;
            $supported = ! str_starts_with($meta['mime_type'], 'application/vnd.google-apps.')
                && PageFiles::acceptedType($meta['mime_type'], $name) !== null
                && ($meta['size'] === null || $meta['size'] <= $maxBytes);
            $out[] = [
                'drive_file_id' => $file['id'],
                'title' => mb_substr($name, 0, 255),
                'mime_type' => mb_substr($meta['mime_type'], 0, 100),
                'supported' => $supported,
            ];
        }

        return $out;
    }

    /**
     * Downloads the readable materials (within the limits of one document
     * read) and asks Gemini for a draft key.
     *
     * @param  array<string, mixed>  $work
     * @param  list<array{drive_file_id: string, title: string, mime_type: string, supported: bool}>  $materials
     */
    private function draftKey(GoogleApi $api, User $owner, Assignment $assignment, array $work, array $materials): void
    {
        $maxFiles = (int) config('eduvision.documents.max_files');
        $maxPages = (int) config('eduvision.documents.max_pages');
        $maxTotal = (int) config('eduvision.documents.max_total_mb') * 1024 * 1024;
        $ids = [];
        $pages = 0;
        $bytesTotal = 0;
        foreach ($materials as $material) {
            if (! $material['supported'] || count($ids) >= $maxFiles) {
                continue;
            }
            try {
                $bytes = $api->downloadDriveFile($material['drive_file_id']);
                $document = $this->documents->storeBytes($owner, $bytes, $material['mime_type'], $material['title']);
            } catch (GoogleApiException|ApiException $e) {
                Log::warning('google.coursework_material_skipped', ['assignment_id' => $assignment->id, 'reason' => $e instanceof ApiException ? $e->errorCode : $e->kind]);

                continue;
            }
            /** @var SourceDocument $document */
            if ($pages + $document->page_count > $maxPages || $bytesTotal + $document->size_bytes > $maxTotal) {
                continue; // one read takes at most 30 pages / 20 MB; the teacher can attach the rest
            }
            $pages += $document->page_count;
            $bytesTotal += $document->size_bytes;
            $ids[] = $document->id;
        }

        $description = trim((string) ($work['description'] ?? ''));
        if ($ids === [] && $description === '') {
            Log::info('google.coursework_not_drafted', ['assignment_id' => $assignment->id, 'reason' => 'nothing_to_read']);

            return;
        }
        $text = Str::limit(trim($assignment->title."\n\n".$description), self::MAX_COURSEWORK_TEXT, '');

        try {
            $this->keys->request($owner, $assignment, AnswerKeyResult::KIND_DRAFT, ['document_ids' => $ids], $text);
        } catch (ApiException $e) {
            // ai_key_missing and the like: the mirror waits for the teacher's key.
            Log::info('google.coursework_not_drafted', ['assignment_id' => $assignment->id, 'reason' => $e->errorCode]);
        }
    }

    /**
     * dueDate + dueTime are in UTC (Classroom API).
     *
     * @param  array<string, mixed>  $work
     */
    private static function dueAt(array $work): ?Carbon
    {
        $date = $work['dueDate'] ?? null;
        if (! is_array($date) || ! isset($date['year'], $date['month'], $date['day'])) {
            return null;
        }
        $time = is_array($work['dueTime'] ?? null) ? $work['dueTime'] : [];

        try {
            return Carbon::create((int) $date['year'], (int) $date['month'], (int) $date['day'], (int) ($time['hours'] ?? 0), (int) ($time['minutes'] ?? 0), 0, 'UTC');
        } catch (Throwable) {
            return null;
        }
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
}
