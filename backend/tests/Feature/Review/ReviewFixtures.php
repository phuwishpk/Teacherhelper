<?php

namespace Tests\Feature\Review;

use App\Domain\Grading\ResponseGrader;
use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\ScanGrader;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Layout;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * An assignment with graded answers written straight into the tables (no
 * scan pipeline), so each test sets exactly the grading state it needs:
 *   q1 short (numeric, 2 pts), q2 short (text, 2 pts), q3 show_work (5 pts), q4 mcq (1 pt)
 * printed by layout v1 on two pages (page 1: q1, q2; page 2: q3, q4),
 * and students numbered 1..n in the teacher's classroom.
 */
trait ReviewFixtures
{
    protected User $teacher;

    protected Classroom $classroom;

    protected Assignment $assignment;

    /** @var array<string, Question> q1..q4 */
    protected array $q = [];

    /** @var list<User> students[0] has number 1 */
    protected array $students = [];

    protected function makeReviewWorld(int $students = 2): void
    {
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        foreach (range(1, $students) as $n) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, "นักเรียนคนที่ {$n}")['student'];
        }
        $this->assignment = Assignment::factory()->for_classroom($this->classroom)->create([
            'title' => 'การบ้านเศษส่วน',
            'status' => Assignment::STATUS_READY,
            'current_layout_version' => 1,
        ]);
        $this->q['q1'] = Question::factory()->short()->create(['assignment_id' => $this->assignment->id]);
        $this->q['q2'] = Question::factory()->short(false)->create(['assignment_id' => $this->assignment->id]);
        $this->q['q3'] = Question::factory()->showWork()->create(['assignment_id' => $this->assignment->id]);
        $this->q['q4'] = Question::factory()->create(['assignment_id' => $this->assignment->id]);

        $region = fn (string $q, string $kind) => [
            'region_id' => 'q'.$this->q[$q]->id, 'question_id' => $this->q[$q]->id, 'kind' => $kind,
            'rect' => ['x' => 0.08, 'y' => 0.2, 'w' => 0.6, 'h' => 0.1],
        ];
        Layout::create([
            'assignment_id' => $this->assignment->id,
            'version' => 1,
            'pages' => [
                ['assignment_id' => $this->assignment->id, 'version' => 1, 'page' => 1, 'page_count' => 2, 'regions' => [$region('q1', 'box'), $region('q2', 'box')]],
                ['assignment_id' => $this->assignment->id, 'version' => 1, 'page' => 2, 'page_count' => 2, 'regions' => [$region('q3', 'lines'), $region('q4', 'mcq')]],
            ],
        ]);
    }

    /**
     * AI-scored answers to every question of the sheet (both pages).
     *
     * @return array<string, Response> q1..q4
     */
    protected function answerSheet(User $student): array
    {
        return array_map(fn (string $q) => $this->answer($student, $q), ['q1' => 'q1', 'q2' => 'q2', 'q3' => 'q3', 'q4' => 'q4']);
    }

    protected function submission(User $student, string $status = Submission::STATUS_NEEDS_REVIEW): Submission
    {
        return Submission::query()->firstOrCreate(
            ['assignment_id' => $this->assignment->id, 'student_id' => $student->id],
            ['status' => $status],
        );
    }

    protected function scanFor(Submission $submission, int $page = 1, string $state = Scan::STATE_ACTIVE): Scan
    {
        return Scan::create([
            'client_scan_id' => (string) Str::uuid(),
            'submission_id' => $submission->id,
            'page_no' => $page,
            'layout_version' => 1,
            'uploaded_by' => $this->teacher->id,
            'scanned_at' => now(),
            'blur_score' => 150.0,
            'state' => $state,
        ]);
    }

    /**
     * A response graded by the AI: by default scored, half marks, `look`.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function answer(User $student, string $q, array $attributes = []): Response
    {
        $submission = $this->submission($student);
        $scan = $submission->scans()->first() ?? $this->scanFor($submission);
        $question = $this->q[$q];

        return Response::create([
            'submission_id' => $submission->id,
            'question_id' => $question->id,
            'scan_id' => $scan->id,
            'crop_path' => "crops/{$this->assignment->school_id}/{$this->assignment->id}/x-{$q}-{$student->id}.webp",
            'grading_state' => Response::STATE_SCORED,
            'ai_score' => (float) $question->max_points / 2,
            'ai_understanding' => 'partial',
            'ai_error_types' => ['calculation'],
            'review_priority' => 0.3,
            'priority_band' => ReviewPriority::BAND_LOOK,
            'fuzzy_trace' => ['system' => $question->type, 'suspicious_instruction' => false],
            'extraction' => null,
            'explanation' => 'ลองตรวจการคำนวณอีกครั้งนะ',
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function manualAnswer(User $student, string $q, string $reason = ScanGrader::REASON_KEY_MISSING, array $attributes = []): Response
    {
        return $this->answer($student, $q, [
            'grading_state' => Response::STATE_MANUAL,
            'ai_score' => null,
            'ai_understanding' => null,
            'ai_error_types' => null,
            'review_priority' => 1.0,
            'priority_band' => ReviewPriority::BAND_CHECK,
            'fuzzy_trace' => ['manual_reason' => $reason],
            'explanation' => null,
            ...$attributes,
        ]);
    }

    /** @param array<string, mixed> $attributes */
    protected function confidentAnswer(User $student, string $q, array $attributes = []): Response
    {
        return $this->answer($student, $q, [
            'ai_score' => (float) $this->q[$q]->max_points,
            'ai_understanding' => 'good',
            'ai_error_types' => [],
            'review_priority' => 0.05,
            'priority_band' => ReviewPriority::BAND_CONFIDENT,
            ...$attributes,
        ]);
    }

    /**
     * A short-answer response with a real extraction and fuzzy trace
     * (ResponseGrader), as GradeScanJob would store it.
     *
     * @param  array<string, mixed>  $extraction
     */
    protected function gradedShort(User $student, string $q, array $extraction, ?string $cnnText = '20'): Response
    {
        $question = $this->q[$q];
        $extraction += [
            'blank' => false,
            'suspicious_instruction' => false,
            'legibility' => 'clear',
            'answer_text' => '20',
            'key_match' => 'exact',
            'error_types' => [],
        ];
        $grade = ResponseGrader::grade($question, [], 'normal', $extraction, $cnnText, 0.08);

        return $this->answer($student, $q, [
            'grading_state' => $grade->state,
            'extraction' => $extraction,
            'fuzzy_trace' => $grade->trace,
            'ai_score' => $grade->score,
            'ai_understanding' => $grade->understanding,
            'ai_error_types' => $grade->errorTypes,
            'review_priority' => $grade->reviewPriority,
            'priority_band' => $grade->priorityBand,
            'cnn_text' => $cnnText,
            'cnn_confidence' => $cnnText === null ? null : 0.95,
            'ink_ratio' => 0.08,
        ]);
    }

    /** Marks every answer of the submission reviewed with the AI score (or $score for manual ones). */
    protected function reviewAll(Submission $submission, float $manualScore = 1.0): void
    {
        foreach ($submission->responses as $response) {
            $response->forceFill([
                'final_score' => $response->ai_score ?? $manualScore,
                'final_understanding' => $response->ai_understanding ?? 'partial',
                'final_error_types' => $response->ai_error_types ?? [],
                'reviewed_by' => $this->teacher->id,
                'reviewed_at' => now(),
            ])->save();
        }
        $submission->forceFill(['status' => Submission::STATUS_REVIEWED])->save();
    }
}
