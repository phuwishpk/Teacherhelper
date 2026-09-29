<?php

namespace App\Domain\Grading;

use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PageExtractionRequests;
use App\Domain\Gemini\RubricDraftRequest;
use App\Domain\Notifications\GradingNotices;
use App\Domain\Pages\PageFiles;
use App\Domain\Scans\SubmissionStatus;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The whole-page grading path behind GradeSubmissionPageJob (DESIGN §19.4,
 * §21.4):
 *
 * readPage(), per file:
 * 1. one `extract_page` call with every question of the assignment (at most
 *    services.gemini.page_max_questions per call), the file at the page
 *    media resolution;
 * 2. a question the call left out or answered against its schema is asked
 *    again once, alone, on the same file (per-question retry);
 * 3. the per-question result is stored on the page (submission_pages.result)
 *    and the page becomes `graded`. A transport error makes the job try
 *    again (60 / 180 / 600 s); after 3 runs, or with no usable key, the page
 *    is `failed`.
 *
 * merge(), once no page of the round is still `grading`:
 * - a question found on one page takes that page's answer; found on several,
 *   the first page with a non-blank answer; two non-blank answers that
 *   differ give D = 1 (the only source of D here: no CNN, no ink ratio);
 * - fuzzy system 1 and 2 as in §11 (mcq by code from the options Gemini
 *   read, §11.6), explanations as in the crop path (GradeApplier);
 * - a question found on no page is `manual` with band `check` ("ต้องตรวจ")
 *   and the reason answer_not_found ("หาคำตอบข้อนี้ในภาพไม่เจอ"); one that
 *   failed its schema twice is invalid_output, one on failed pages ai_error
 *   or the key reason;
 * - one transaction under the submission lock writes every response that is
 *   still `queued` and still belongs to this round, then the teacher is told
 *   like after a scan (GradingNotices).
 */
final class WholePageGrader
{
    public const REASON_ANSWER_NOT_FOUND = 'answer_not_found';

    public const REASON_FILE_MISSING = 'page_file_missing';

    /** submission_pages.result.status */
    public const PAGE_OK = 'ok';

    public const PAGE_ERROR = 'error';

    public const PAGE_KEY_MISSING = 'key_missing';

    public const PAGE_KEY_INVALID = 'key_invalid';

    public const PAGE_FILE_MISSING = 'file_missing';

    public function __construct(
        private readonly GeminiGateway $gateway,
        private readonly GeminiKeyResolver $keys,
        private readonly PageExtractionRequests $requests,
        private readonly GradeApplier $applier,
        private readonly GradingNotices $notices,
    ) {}

    /**
     * @return bool true when the job should try again later (transport error, attempts left)
     */
    public function gradePage(int $pageId, int $attempt): bool
    {
        $page = SubmissionPage::query()->with(['submission.assignment.classroom', 'submission.assignment.subject'])->find($pageId);
        $assignment = $page?->submission?->assignment;
        if ($page === null || $assignment === null) {
            return false;
        }
        if ($page->state !== SubmissionPage::STATE_GRADING) {
            // Read already (a crash between saving and merging) or replaced by a newer hand-in.
            $this->merge($page->submission_id, $this->keys->forTeacher($assignment->classroom?->teacher_id));

            return false;
        }

        $key = $this->keys->forTeacher($assignment->classroom?->teacher_id);
        if ($key === null) {
            $this->finish($page, SubmissionPage::STATE_FAILED, ['status' => self::PAGE_KEY_MISSING, 'questions' => []]);
            $this->merge($page->submission_id, null);

            return false;
        }

        $disk = PageFiles::disk();
        if ($page->file_path === null || ! $disk->exists($page->file_path)) {
            $this->finish($page, SubmissionPage::STATE_FAILED, ['status' => self::PAGE_FILE_MISSING, 'questions' => []]);
            $this->merge($page->submission_id, $key);

            return false;
        }

        $result = $this->read($page, $assignment, (string) $disk->get($page->file_path), $key);
        if ($result['status'] === self::PAGE_ERROR && $attempt < ScanGrader::MAX_ATTEMPTS) {
            return true;
        }

        $state = in_array($result['status'], [self::PAGE_OK], true) ? SubmissionPage::STATE_GRADED : SubmissionPage::STATE_FAILED;
        $this->finish($page, $state, $result);
        $this->merge($page->submission_id, $key);

        return false;
    }

    /** The job ran out of tries: the page counts as failed and the round is merged. */
    public function giveUp(int $pageId): void
    {
        $page = SubmissionPage::query()->with('submission.assignment.classroom')->find($pageId);
        if ($page === null) {
            return;
        }
        if ($page->state === SubmissionPage::STATE_GRADING) {
            $this->finish($page, SubmissionPage::STATE_FAILED, ['status' => self::PAGE_ERROR, 'questions' => []]);
        }
        $this->merge($page->submission_id, $this->keys->forTeacher($page->submission?->assignment?->classroom?->teacher_id));
    }

    /**
     * One file, every question: status ok (questions hold what was read),
     * error (Gemini failed, the job may retry) or key_invalid.
     *
     * @return array{status: string, questions: array<int, array<string, mixed>>}
     */
    private function read(SubmissionPage $page, Assignment $assignment, string $bytes, GeminiKey $key): array
    {
        $items = [];
        foreach ($this->questions($assignment) as $question) {
            if ($question->type === Question::TYPE_OPEN && $question->rubricCriteria->isEmpty()) {
                continue; // manual rubric_missing at the merge; nothing to read against
            }
            $items[(int) $question->position] = ['question' => $question, 'criteria' => $question->rubricCriteria->all()];
        }
        if ($items === []) {
            return ['status' => self::PAGE_OK, 'questions' => []];
        }

        $gradeLabel = RubricDraftRequest::gradeLabel((int) $assignment->classroom?->grade_level);
        $subject = (string) $assignment->subject?->name;
        $max = max(1, (int) config('services.gemini.page_max_questions', 15));
        $chunks = array_chunk($items, $max, true);
        $calls = [];
        foreach ($chunks as $i => $chunk) {
            $calls[$i] = $this->requests->forFile($bytes, $page->mime_type, $page->page_count, array_values($chunk), $subject, $gradeLabel, $assignment->id);
        }
        $outcomes = $this->gateway->run($calls, $key);

        $questions = [];
        $retry = [];
        foreach ($chunks as $i => $chunk) {
            $outcome = $outcomes[$i];
            if ($outcome->status === CallOutcome::KEY_INVALID) {
                return ['status' => self::PAGE_KEY_INVALID, 'questions' => []];
            }
            if ($outcome->status === CallOutcome::ERROR) {
                return ['status' => self::PAGE_ERROR, 'questions' => []];
            }
            $answers = $outcome->isOk() ? (array) ($outcome->data['answers'] ?? []) : [];
            foreach ($chunk as $no => $item) {
                if (isset($answers[$no])) {
                    $questions[$item['question']->id] = self::stored($answers[$no]);
                } else {
                    $retry[$no] = $item; // left out, or failed its schema (§19.4: retry once, alone)
                }
            }
        }

        if ($retry !== []) {
            $calls = [];
            foreach ($retry as $no => $item) {
                $calls[$no] = $this->requests->forFile($bytes, $page->mime_type, $page->page_count, [$item], $subject, $gradeLabel, $assignment->id);
            }
            $outcomes = $this->gateway->run($calls, $key);
            foreach ($retry as $no => $item) {
                $outcome = $outcomes[$no];
                $answer = $outcome->isOk() ? ($outcome->data['answers'][$no] ?? null) : null;
                $questions[$item['question']->id] = match (true) {
                    is_array($answer) => self::stored($answer),
                    $outcome->status === CallOutcome::KEY_INVALID => ['error' => self::PAGE_KEY_INVALID],
                    $outcome->status === CallOutcome::ERROR => ['error' => self::PAGE_ERROR],
                    default => ['invalid' => true],
                };
            }
        }

        return ['status' => self::PAGE_OK, 'questions' => $questions];
    }

    /**
     * @param  array{found: bool, data: array<string, mixed>|null, answer_box: list<int>|null}  $answer
     * @return array<string, mixed>
     */
    private static function stored(array $answer): array
    {
        return $answer['found']
            ? ['found' => true, 'data' => $answer['data'], 'answer_box' => $answer['answer_box']]
            : ['found' => false];
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function finish(SubmissionPage $page, string $state, array $result): void
    {
        DB::transaction(function () use ($page, $state, $result) {
            Submission::query()->lockForUpdate()->find($page->submission_id);
            $locked = SubmissionPage::query()->lockForUpdate()->find($page->id);
            if ($locked === null || $locked->state !== SubmissionPage::STATE_GRADING) {
                return; // a newer hand-in replaced this round meanwhile
            }
            $locked->state = $state;
            $locked->result = $result;
            $locked->save();
        });
    }

    /**
     * Grades the round once every page is read. Safe to call more than once:
     * only responses still `queued` in this round are written.
     */
    public function merge(int $submissionId, ?GeminiKey $key): void
    {
        $submission = Submission::query()->with(['assignment.classroom'])->find($submissionId);
        $assignment = $submission?->assignment;
        if ($submission === null || $assignment === null) {
            return;
        }
        $pages = $submission->pages()->whereIn('state', SubmissionPage::CURRENT_STATES)->orderBy('position')->orderBy('id')->get();
        if ($pages->isEmpty() || $pages->contains('state', SubmissionPage::STATE_GRADING)) {
            return;
        }
        $pageIds = array_map('intval', $pages->modelKeys());
        $responses = Response::query()
            ->where('submission_id', $submission->id)
            ->whereIn('submission_page_id', $pageIds)
            ->where('grading_state', Response::STATE_QUEUED)
            ->with('question.rubricCriteria')
            ->orderBy('id')
            ->get();
        if ($responses->isEmpty()) {
            return;
        }

        $graded = [];
        $extractions = [];
        $manual = [];
        $pageOf = [];
        $traces = [];
        foreach ($responses as $response) {
            $question = $response->question;
            $criteria = $question->rubricCriteria->all();
            if ($question->type === Question::TYPE_OPEN && $criteria === []) {
                $manual[$response->id] = ScanGrader::REASON_RUBRIC_MISSING;

                continue;
            }

            [$chosen, $conflict, $reason] = self::choose($pages->all(), $question->id);
            if ($chosen === null) {
                $manual[$response->id] = $reason;

                continue;
            }
            [$pageId, $answer] = $chosen;
            $pageOf[$response->id] = $pageId;
            $extractions[$response->id] = $answer['data'] + (is_array($answer['answer_box'] ?? null) ? ['answer_box' => $answer['answer_box']] : []);
            $traces[$response->id] = ['whole_page' => ['page_id' => $pageId, 'pages' => count($pageIds), 'page_conflict' => $conflict]];
            $graded[$response->id] = $question->type === Question::TYPE_MCQ
                ? self::gradeMcq($question, $answer['data'], $conflict)
                : ResponseGrader::grade($question, $criteria, $assignment->strictness, $answer['data'], null, null, $conflict ? 1.0 : 0.0);
        }

        [$explanations, $explanationErrors] = $key === null
            ? [[], []]
            : $this->applier->explain($graded, $responses->keyBy('id')->all(), $extractions, $key, RubricDraftRequest::gradeLabel((int) $assignment->classroom?->grade_level), (bool) $assignment->score_only, PageExtractionRequests::FEATURE, $assignment->id);

        $written = DB::transaction(function () use ($submission, $responses, $pageIds, $graded, $extractions, $manual, $pageOf, $traces, $explanations, $explanationErrors) {
            $locked = Submission::query()->lockForUpdate()->find($submission->id);
            $written = 0;
            foreach ($responses as $loaded) {
                $response = Response::query()->lockForUpdate()->find($loaded->id);
                if ($response === null
                    || ! in_array((int) $response->submission_page_id, $pageIds, true)
                    || $response->grading_state !== Response::STATE_QUEUED) {
                    continue; // a newer hand-in or a scan took this answer over meanwhile
                }
                if (isset($pageOf[$response->id])) {
                    $response->submission_page_id = $pageOf[$response->id];
                }
                if (isset($manual[$response->id])) {
                    GradeApplier::markManual($response, $manual[$response->id]);
                } elseif (isset($graded[$response->id])) {
                    GradeApplier::applyGrade(
                        $response,
                        $graded[$response->id],
                        $extractions[$response->id],
                        $explanations[$response->id] ?? null,
                        $explanationErrors[$response->id] ?? null,
                        $traces[$response->id],
                    );
                }
                $written++;
            }
            if ($locked !== null) {
                SubmissionStatus::refresh($locked);
            }

            return $written;
        });

        if ($written > 0) {
            $this->notices->answersFinished($assignment);
        }
    }

    /**
     * The answer of one question across the pages of the round.
     *
     * @param  list<SubmissionPage>  $pages  in position order
     * @return array{0: array{0: int, 1: array<string, mixed>}|null, 1: bool, 2: string} [page id, answer] or null, conflict, manual reason
     */
    private static function choose(array $pages, int $questionId): array
    {
        $found = [];
        $invalid = false;
        $failed = null;
        foreach ($pages as $page) {
            $result = $page->result ?? [];
            $status = (string) ($result['status'] ?? self::PAGE_ERROR);
            if ($status !== self::PAGE_OK) {
                $failed ??= $status;

                continue;
            }
            $entry = $result['questions'][$questionId] ?? $result['questions'][(string) $questionId] ?? null;
            if (! is_array($entry)) {
                continue;
            }
            if (($entry['found'] ?? false) === true && is_array($entry['data'] ?? null)) {
                $found[] = [$page->id, $entry];
            } elseif (($entry['invalid'] ?? false) === true) {
                $invalid = true;
            } elseif (isset($entry['error'])) {
                $failed ??= (string) $entry['error'];
            }
        }

        if ($found === []) {
            $reason = match (true) {
                $invalid => ScanGrader::REASON_INVALID_OUTPUT,
                $failed === self::PAGE_KEY_MISSING => ScanGrader::REASON_KEY_MISSING,
                $failed === self::PAGE_KEY_INVALID => ScanGrader::REASON_KEY_INVALID,
                $failed === self::PAGE_FILE_MISSING => self::REASON_FILE_MISSING,
                $failed !== null => ScanGrader::REASON_AI_ERROR,
                default => self::REASON_ANSWER_NOT_FOUND,
            };

            return [null, false, $reason];
        }

        $answered = array_values(array_filter($found, fn (array $f) => ($f[1]['data']['blank'] ?? false) !== true));
        if ($answered === []) {
            return [$found[0], false, ''];
        }
        $readings = array_unique(array_map(fn (array $f) => self::reading($f[1]['data']), $answered));

        return [$answered[0], count($readings) > 1, ''];
    }

    /**
     * What the student wrote, normalised, to compare two pages.
     *
     * @param  array<string, mixed>  $data
     */
    private static function reading(array $data): string
    {
        $text = match (true) {
            isset($data['selected_options']) => implode(',', (array) $data['selected_options']),
            isset($data['steps']) => implode("\n", array_map(fn (array $s) => (string) $s['text'], (array) $data['steps']))."\n".($data['final_answer_text'] ?? ''),
            isset($data['transcription']) => (string) $data['transcription'],
            default => (string) ($data['answer_text'] ?? ''),
        };

        return AnswerMatcher::normalize($text);
    }

    /**
     * mcq from a whole page (§11.6 by code): the options Gemini read become
     * the fill (1 marked, 0 not), so one marked option equal to the key
     * scores; two pages that disagree, or a suspicious note, raise the review.
     *
     * @param  array<string, mixed>  $data
     */
    private static function gradeMcq(Question $question, array $data, bool $conflict): GradeOutcome
    {
        $correct = $question->answer_key['correct'] ?? null;
        $suspicious = (bool) ($data['suspicious_instruction'] ?? false);
        if (! is_string($correct)) {
            $priority = ReviewPriority::manual();

            return new GradeOutcome(GradeResult::MANUAL, null, null, null, [], $priority->storedP(), $priority->band, [
                'system' => 'mcq',
                'manual_reason' => 'answer_key_missing',
                'priority' => $priority->toArray(),
            ], manualReason: 'answer_key_missing');
        }

        $selected = array_map('strval', (array) ($data['selected_options'] ?? []));
        $fill = [];
        foreach (Question::MCQ_OPTIONS as $option) {
            $fill[$option] = in_array($option, $selected, true) ? 1.0 : 0.0;
        }
        $grade = McqGrader::grade($fill, $correct, (float) $question->max_points);
        $trace = $grade->trace + ['read_by' => 'gemini_page', 'suspicious_instruction' => $suspicious];
        $reviewPriority = $grade->reviewPriority;
        $band = $grade->priorityBand;
        if ($conflict || $suspicious) {
            $u = (float) $grade->scoreRatio;
            $priority = ReviewPriority::evaluate($conflict ? 1.0 : (float) ($grade->trace['review_priority']['inputs']['D'] ?? 0.0), 0.0, ReviewPriority::boundaryCloseness($u), $suspicious);
            $reviewPriority = $priority->storedP();
            $band = $priority->band;
            $trace['review_priority'] = $priority->toArray();
            $trace['priority'] = $priority->toArray();
        }

        return new GradeOutcome(
            state: GradeResult::SCORED,
            scoreRatio: $grade->scoreRatio,
            score: $grade->score,
            understanding: $grade->understanding,
            errorTypes: array_values(array_unique([...$grade->errorTypes, ...array_map('strval', (array) ($data['error_types'] ?? []))])),
            reviewPriority: $reviewPriority,
            priorityBand: $band,
            trace: $trace,
            blank: (bool) ($data['blank'] ?? false),
            suspicious: $suspicious,
        );
    }

    /**
     * @return Collection<int, Question>
     */
    private function questions(Assignment $assignment): Collection
    {
        return $assignment->questions()->with('rubricCriteria')->orderBy('position')->get();
    }
}
