<?php

namespace App\Domain\Pages;

use App\Domain\Scans\ResponseWriter;
use App\Domain\Scans\ScanFiles;
use App\Domain\Scans\SubmissionStatus;
use App\Events\SubmissionReopened;
use App\Exceptions\ApiException;
use App\Jobs\GradeSubmissionPageJob;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The whole-page hand-in of one student (DESIGN §19.4): the files are
 * stored as `submission_pages`, then graded at once or kept for the teacher.
 *
 * receive(): the files of one hand-in become `stored` pages (an earlier
 * hand-in that was never graded is superseded). They are graded right away
 * when the submission has nothing graded yet, or when the teacher sent the
 * work back for a retake ($retake: Classroom's returned_for_retake). Any
 * other new hand-in waits: submissions.regrade_pending = TRUE until the
 * teacher presses "ตรวจ" (POST /submissions/{id}/grade -> start()).
 *
 * start(): the stored pages become the grading round: the pages of the
 * previous round are superseded, every question of the assignment gets its
 * response reset to `queued` and pointed at the first page (scan_id NULL,
 * crops dropped; a score it had is logged as a `rescan` score event), and
 * one GradeSubmissionPageJob per page is queued. A published submission is
 * reopened (SubmissionReopened, §14.2).
 *
 * Before the teacher approved the answer key (assignments.key_approved_at,
 * §19.5) nothing is graded: the pages stay `stored` (waiting_key) until
 * ReleaseWaitingSubmissionsJob starts them, and start() answers 409
 * answer_key_not_approved.
 *
 * Identity is the submitter (Classroom userId or the logged-in student):
 * nothing on the page, QR included, decides whose work it is.
 */
final class WholePageSubmissions
{
    /**
     * @param  list<array{bytes: string, mime_type: string, page_count: int, drive_file_id?: string|null}>  $files  already checked (type, size, pages)
     * @param  array{google_submission_id?: string|null, uploaded_by?: int|null, submitted_at?: Carbon|null, late?: bool}  $meta
     * @return array{submission: Submission, pages: list<SubmissionPage>, grading: bool, waiting_key: bool}
     */
    public function receive(Assignment $assignment, int $studentId, array $files, string $source, array $meta, bool $retake): array
    {
        $created = Submission::query()->createOrFirst([
            'assignment_id' => $assignment->id,
            'student_id' => $studentId,
        ]);
        $submissionId = $created->id;

        $written = [];
        $stale = [];
        try {
            [$submission, $pages, $toGrade] = DB::transaction(function () use ($assignment, $submissionId, $files, $source, $meta, $retake, &$written, &$stale) {
                $submission = Submission::query()->lockForUpdate()->findOrFail($submissionId);

                // A hand-in that was never graded is replaced by this one.
                foreach ($submission->pages()->where('state', SubmissionPage::STATE_STORED)->get() as $old) {
                    $old->state = SubmissionPage::STATE_SUPERSEDED;
                    $old->save();
                }

                $pages = [];
                foreach (array_values($files) as $i => $file) {
                    $page = SubmissionPage::create([
                        'submission_id' => $submission->id,
                        'source' => $source,
                        'google_submission_id' => $meta['google_submission_id'] ?? null,
                        'drive_file_id' => $file['drive_file_id'] ?? null,
                        'uploaded_by' => $meta['uploaded_by'] ?? null,
                        'position' => $i + 1,
                        'mime_type' => $file['mime_type'],
                        'page_count' => min(255, max(1, $file['page_count'])),
                        'size_bytes' => strlen($file['bytes']),
                        'sha256' => hash('sha256', $file['bytes']),
                        'state' => SubmissionPage::STATE_STORED,
                        'received_at' => now(),
                    ]);
                    $path = PageFiles::path($assignment->school_id, $assignment->id, $page->id, $file['mime_type']);
                    $written[] = $path;
                    PageFiles::disk()->put($path, $file['bytes']);
                    $page->file_path = $path;
                    $page->save();
                    $pages[] = $page;
                }

                $submission->submitted_at = $meta['submitted_at'] ?? now();
                $submission->late = (bool) ($meta['late'] ?? false);
                $graded = $submission->responses()->exists();
                $toGrade = [];
                if (! Assignment::query()->whereKey($assignment->id)->whereNotNull('key_approved_at')->exists()) {
                    // No approved key yet (§19.5): keep the pages; ReleaseWaitingSubmissionsJob grades
                    // them after approval, or the teacher's "ตรวจ" for a submission graded before.
                    if ($graded) {
                        $submission->regrade_pending = true;
                    }
                    $submission->save();
                } elseif (! $graded || $retake) {
                    $toGrade = $this->startLocked($submission, $assignment, $stale);
                } else {
                    $submission->regrade_pending = true;
                    $submission->save();
                }

                return [$submission, $pages, $toGrade];
            });
        } catch (Throwable $e) {
            PageFiles::disk()->delete($written);
            if ($created->wasRecentlyCreated) {
                // The row made for this hand-in stays empty: without it GET /assignments
                // counted a hand-in that never arrived. Only if still empty (a scan or
                // another hand-in may have used it meanwhile).
                try {
                    Submission::query()->whereKey($submissionId)
                        ->whereDoesntHave('pages')->whereDoesntHave('responses')->whereDoesntHave('scans')
                        ->delete();
                } catch (Throwable) {
                    // Never hide the error that got us here.
                }
            }

            throw $e;
        }

        $this->afterStart($toGrade, $stale);
        Log::info('pages.received', [
            'submission_id' => $submission->id,
            'pages' => count($pages),
            'source' => $source,
            'grading' => $toGrade !== [],
            'regrade_pending' => $submission->regrade_pending,
        ]);

        return [
            'submission' => $submission,
            'pages' => $pages,
            'grading' => $toGrade !== [],
            'waiting_key' => $toGrade === [] && ! $submission->regrade_pending,
        ];
    }

    /**
     * "ตรวจ" for a new hand-in that waits (regrade_pending): grades the stored pages.
     *
     * @return int pages queued for grading
     *
     * @throws ApiException 409 nothing_to_grade / answer_key_not_approved
     */
    public function start(Submission $submission): int
    {
        $stale = [];
        $toGrade = DB::transaction(function () use ($submission, &$stale) {
            $locked = Submission::query()->with('assignment')->lockForUpdate()->findOrFail($submission->id);
            if (! $locked->assignment->keyApproved()) {
                throw new ApiException('ยังไม่ได้อนุมัติเฉลยของการบ้านนี้ อนุมัติเฉลยก่อนจึงจะตรวจได้', 'answer_key_not_approved', 409);
            }

            return $this->startLocked($locked, $locked->assignment, $stale);
        });
        if ($toGrade === []) {
            throw new ApiException('ไม่มีงานที่ส่งใหม่ให้ตรวจ', 'nothing_to_grade', 409);
        }
        $this->afterStart($toGrade, $stale);

        return count($toGrade);
    }

    /**
     * Pages of the current round (after a missing Gemini key was added, say)
     * are read again from the start. Inside the caller's transaction, which
     * holds the submission lock.
     *
     * @return list<int> page ids to queue
     */
    public static function restartCurrentRound(Submission $submission): array
    {
        $pages = $submission->pages()->whereIn('state', SubmissionPage::CURRENT_STATES)->get();
        foreach ($pages as $page) {
            $page->state = SubmissionPage::STATE_GRADING;
            $page->result = null;
            $page->save();
        }

        return $pages->modelKeys();
    }

    /**
     * @param  list<int>  $pageIds
     */
    public static function dispatch(array $pageIds): void
    {
        foreach ($pageIds as $pageId) {
            GradeSubmissionPageJob::dispatch($pageId);
        }
    }

    /**
     * Runs under the submission lock.
     *
     * @param  list<string>  $stale  crop files the reset responses no longer use (deleted after commit)
     * @return list<int> page ids to grade
     */
    private function startLocked(Submission $submission, Assignment $assignment, array &$stale): array
    {
        $stored = $submission->pages()->where('state', SubmissionPage::STATE_STORED)->orderBy('position')->orderBy('id')->get();
        if ($stored->isEmpty()) {
            return [];
        }

        $submission->pages()
            ->whereIn('state', SubmissionPage::CURRENT_STATES)
            ->update(['state' => SubmissionPage::STATE_SUPERSEDED, 'updated_at' => now()]);

        $first = $stored->first();
        $questions = $assignment->questions()->orderBy('position')->get();
        foreach ($questions as $question) {
            /** @var Question $question */
            $this->resetResponse($submission, $question, $first, $stale);
        }

        foreach ($stored as $page) {
            $page->state = SubmissionPage::STATE_GRADING;
            $page->result = null;
            $page->save();
        }

        $wasPublished = $submission->isPublished();
        $submission->channel = Submission::CHANNEL_WHOLE_PAGE;
        $submission->regrade_pending = false;
        SubmissionStatus::refresh($submission, reopen: true);
        if ($wasPublished) {
            // After the commit (ShouldDispatchAfterCommit): mastery drops the rows of the reopened submission (§14.2).
            SubmissionReopened::dispatch($submission->id, $submission->assignment_id, $submission->student_id);
        }

        return $stored->modelKeys();
    }

    /**
     * @param  list<string>  $stale
     */
    private function resetResponse(Submission $submission, Question $question, SubmissionPage $page, array &$stale): void
    {
        $response = Response::query()
            ->where('submission_id', $submission->id)
            ->where('question_id', $question->id)
            ->first();

        $previous = null;
        if ($response !== null) {
            array_push($stale, ...array_filter([$response->crop_path, $response->final_crop_path]));
            if ($response->effectiveScore() !== null || $response->effectiveUnderstanding() !== null) {
                $previous = [$response->effectiveScore(), $response->effectiveUnderstanding()];
            }
        } else {
            $response = new Response(['submission_id' => $submission->id, 'question_id' => $question->id]);
        }

        $response->fill(ResponseWriter::RESET);
        $response->fill([
            'scan_id' => null,
            'submission_page_id' => $page->id,
            'crop_path' => null,
            'final_crop_path' => null,
            'ink_ratio' => null,
            'mcq_fill' => null,
            'cnn_text' => null,
            'cnn_confidence' => null,
        ]);
        $response->save();

        if ($previous !== null) {
            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => ScoreEvent::ACTOR_SYSTEM,
                'actor_user_id' => null,
                'action' => ScoreEvent::ACTION_RESCAN,
                'old_score' => $previous[0],
                'new_score' => null,
                'old_understanding' => $previous[1],
                'new_understanding' => null,
                'reason' => "new hand-in graded: whole-page submission, first page {$page->id}",
            ]);
        }
    }

    /**
     * @param  list<int>  $pageIds
     * @param  list<string>  $stale
     */
    private function afterStart(array $pageIds, array $stale): void
    {
        if ($stale !== []) {
            ScanFiles::disk()->delete($stale);
        }
        self::dispatch($pageIds);
    }
}
