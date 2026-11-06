<?php

namespace App\Domain\Firestore;

use App\Domain\Grading\McqGrader;
use App\Jobs\MirrorClassroomRecordsJob;
use App\Jobs\MirrorRecordJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomStudent;
use App\Models\GradebookEntry;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Student records in Firestore (DESIGN §38.10), behind FIRESTORE_MIRROR_RECORDS
 * so real data leaves MariaDB only when an admin decides it does:
 *
 *   classrooms/{id}/students/{studentId}      name, number, student code
 *   classrooms/{id}/submissions/{id}          status, total, answers and scores
 *   classrooms/{id}/gradebook/{entryId}       a typed score
 *
 * MariaDB stays the truth and these are copies for the server side only:
 * firebase/firestore.rules gives no client access outside users/{uid}, so a
 * student never reads a classmate's row or a score that is not published.
 * The app keeps reading the API. No images, e-mail addresses or file paths.
 *
 * A change queues one document (MirrorRecordJob); a merge, a deleted or
 * restored account and a deleted classroom move rows with the query builder,
 * so they queue the whole classroom instead (MirrorClassroomRecordsJob), which
 * also removes the copies left behind.
 */
final class LiveRecords
{
    public const RETENTION_DAYS = 90;

    /** Firestore takes 1 MiB per document: 200 answers of this size still fit. */
    public const MAX_ANSWER_CHARS = 1000;

    public const STUDENTS = 'students';

    public const SUBMISSIONS = 'submissions';

    public const GRADEBOOK = 'gradebook';

    public static function enabled(): bool
    {
        return FirestoreConfig::recordsEnabled();
    }

    public static function classroomPath(int $classroomId): string
    {
        return 'classrooms/'.$classroomId;
    }

    public static function collectionPath(int $classroomId, string $collection): string
    {
        return self::classroomPath($classroomId).'/'.$collection;
    }

    public static function path(int $classroomId, string $collection, int $id): string
    {
        return self::collectionPath($classroomId, $collection).'/'.$id;
    }

    /** A hand-in was saved or deleted. */
    public static function queueSubmission(Submission $submission): void
    {
        if (! self::enabled()) {
            return;
        }
        $classroomId = Assignment::query()->whereKey($submission->assignment_id)->value('classroom_id');
        if ($classroomId !== null) {
            MirrorRecordJob::dispatch(self::SUBMISSIONS, $submission->id, (int) $classroomId)->afterCommit();
        }
    }

    /** An answer's text or score changed: its hand-in's document holds it. */
    public static function queueResponse(Response $response): void
    {
        if (! self::enabled()) {
            return;
        }
        $classroomId = DB::table('submissions')
            ->join('assignments', 'assignments.id', '=', 'submissions.assignment_id')
            ->where('submissions.id', $response->submission_id)
            ->value('assignments.classroom_id');
        if ($classroomId !== null) {
            MirrorRecordJob::dispatch(self::SUBMISSIONS, (int) $response->submission_id, (int) $classroomId)->afterCommit();
        }
    }

    public static function queueEntry(GradebookEntry $entry): void
    {
        if (self::enabled()) {
            MirrorRecordJob::dispatch(self::GRADEBOOK, $entry->id, (int) $entry->classroom_id)->afterCommit();
        }
    }

    /** Someone joined or left, or a number changed. */
    public static function queueRoster(int $classroomId): void
    {
        if (self::enabled()) {
            MirrorClassroomRecordsJob::dispatch($classroomId, rosterOnly: true)->afterCommit();
        }
    }

    /** A student's name or code changed: every roster that shows it. */
    public static function queueRosters(User $student): void
    {
        if (! self::enabled()) {
            return;
        }
        foreach (ClassroomStudent::query()->where('student_id', $student->id)->pluck('classroom_id') as $classroomId) {
            self::queueRoster((int) $classroomId);
        }
    }

    public static function queueClassroom(int $classroomId): void
    {
        if (self::enabled()) {
            MirrorClassroomRecordsJob::dispatch($classroomId)->afterCommit();
        }
    }

    /** The account was deleted, restored or merged: its classrooms, whole. */
    public static function queueAccount(User $user): void
    {
        if (! self::enabled()) {
            return;
        }
        $classroomIds = $user->isTeacher()
            ? Classroom::query()->where('teacher_id', $user->id)->pluck('id')
            : ClassroomStudent::query()->where('student_id', $user->id)->pluck('classroom_id');
        foreach ($classroomIds as $classroomId) {
            self::queueClassroom((int) $classroomId);
        }
    }

    /**
     * The document of one record, read when the job runs, or null when it
     * must not be in Firestore (gone, or its student's account was deleted).
     *
     * @return array<string, mixed>|null
     */
    public static function fields(string $collection, int $id): ?array
    {
        if ($collection === self::SUBMISSIONS) {
            $submission = self::submissions()->find($id);

            return $submission === null ? null : self::submissionFields($submission);
        }
        $entry = GradebookEntry::query()->find($id);
        if ($entry === null || ! User::query()->whereKey($entry->student_id)->whereNull('deleted_at')->exists()) {
            return null;
        }

        return self::entryFields($entry);
    }

    /** @return iterable<int, array<string, mixed>> document by student id */
    public static function rosterDocuments(Classroom $classroom): iterable
    {
        $now = CarbonImmutable::now();
        foreach ($classroom->students()->whereNull('users.deleted_at')->get() as $student) {
            yield (int) $student->id => [
                'classroom_id' => (int) $classroom->id,
                'student_id' => (int) $student->id,
                'name' => (string) $student->name,
                'student_number' => (int) $student->pivot->getAttribute('student_number'),
                'student_code' => $student->student_code,
                ...self::stamps($now),
            ];
        }
    }

    /** @return iterable<int, array<string, mixed>> document by submission id */
    public static function submissionDocuments(Classroom $classroom): iterable
    {
        $assignments = Assignment::query()->select('id')->where('classroom_id', $classroom->id);
        foreach (self::submissions()->whereIn('assignment_id', $assignments)->lazyById(100) as $submission) {
            $fields = self::submissionFields($submission);
            if ($fields !== null) {
                yield (int) $submission->id => $fields;
            }
        }
    }

    /** @return iterable<int, array<string, mixed>> document by entry id */
    public static function gradebookDocuments(Classroom $classroom): iterable
    {
        $students = User::query()->select('id')->whereNull('deleted_at');
        $entries = GradebookEntry::query()->where('classroom_id', $classroom->id)->whereIn('student_id', $students);
        foreach ($entries->lazyById(200) as $entry) {
            yield (int) $entry->id => self::entryFields($entry);
        }
    }

    /** @return array<string, mixed>|null */
    private static function submissionFields(Submission $submission): ?array
    {
        $student = $submission->student;
        $assignment = $submission->assignment;
        if ($student === null || $student->deleted_at !== null || $assignment === null) {
            return null;
        }

        return [
            'classroom_id' => (int) $assignment->classroom_id,
            'assignment_id' => (int) $assignment->id,
            'assignment_title' => (string) $assignment->title,
            'student_id' => (int) $student->id,
            'status' => (string) $submission->status,
            'total_score' => $submission->effectiveTotal(),
            'published' => $submission->isPublished(),
            'answers' => $submission->responses
                ->sortBy(fn (Response $r) => (int) $r->question?->position)
                ->map(fn (Response $r) => [
                    'question_id' => (int) $r->question_id,
                    'position' => (int) $r->question?->position,
                    'type' => (string) $r->question?->type,
                    'answer' => self::answerText($r),
                    'score' => $r->effectiveScore(),
                    'max_points' => (float) $r->question?->max_points,
                ])->values()->all(),
            ...self::stamps(CarbonImmutable::now()),
        ];
    }

    /** @return array<string, mixed> */
    private static function entryFields(GradebookEntry $entry): array
    {
        return [
            'classroom_id' => (int) $entry->classroom_id,
            'student_id' => (int) $entry->student_id,
            'assignment_id' => $entry->assignment_id,
            'gradebook_item_id' => $entry->gradebook_item_id,
            'score' => $entry->score,
            'excused' => (bool) $entry->excused,
            ...self::stamps(CarbonImmutable::now()),
        ];
    }

    /** What the student answered as text; handwriting images never leave the server. */
    private static function answerText(Response $response): string
    {
        $exam = $response->exam_answer;
        if (is_array($exam)) {
            $marked = is_array($exam['resolved'] ?? null) ? $exam['resolved'] : $exam;
            $text = (string) ($marked['value'] ?? implode(',', array_map('strval', (array) ($marked['selected'] ?? []))));
        } else {
            $read = (array) $response->extraction;
            $text = match ($response->question?->type) {
                Question::TYPE_MCQ, Question::TYPE_TRUE_FALSE => implode(',', self::selectedOptions($response, $read)),
                Question::TYPE_SHOW_WORK => trim(implode("\n", array_map(
                    fn (array $step) => (string) ($step['text'] ?? ''),
                    (array) ($read['steps'] ?? []),
                ))."\n".($read['final_answer_text'] ?? '')),
                Question::TYPE_OPEN => (string) ($read['transcription'] ?? ''),
                default => (string) ($read['answer_text'] ?? ''),
            };
        }

        return mb_substr(trim($text), 0, self::MAX_ANSWER_CHARS);
    }

    /**
     * Read from a whole page, or from the bubbles the phone measured on a
     * scanned worksheet (with the grader's own threshold).
     *
     * @param  array<string, mixed>  $read
     * @return list<string>
     */
    private static function selectedOptions(Response $response, array $read): array
    {
        $options = $read['selected_options'] ?? array_keys(array_filter(
            (array) $response->mcq_fill,
            fn ($fill) => (float) $fill >= McqGrader::FILLED_FROM,
        ));

        return array_map('strval', array_values((array) $options));
    }

    /** @return Builder<Submission> */
    private static function submissions(): Builder
    {
        return Submission::query()->with([
            'assignment:id,classroom_id,title',
            'student:id,deleted_at',
            'responses.question:id,position,type,max_points',
        ]);
    }

    /** @return array{updated_at: CarbonImmutable, expires_at: CarbonImmutable} */
    private static function stamps(CarbonImmutable $now): array
    {
        return ['updated_at' => $now, 'expires_at' => $now->addDays(self::RETENTION_DAYS)];
    }
}
