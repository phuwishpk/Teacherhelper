<?php

namespace App\Domain\Review;

use App\Domain\Grading\ReviewPriority;
use App\Domain\Grading\ScanGrader;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * The teacher's review queue of one assignment (DESIGN §9.5, §11.8, §13):
 * every response of its submissions, in this order:
 *
 *   1. `manual` ("ตรวจเอง"), whatever caused it (e.g. ai_key_missing);
 *   2. flagged: suspicious (possible prompt injection) or identity_mismatch;
 *   3. the rest by review_priority, highest first; answers still being
 *      graded (no priority yet) last;
 *
 * ties by student number, then question position. `band` narrows it to one
 * tab the way the app draws tabs: `check` holds manual, flagged and
 * not-yet-graded rows plus priority_band check; `look` and `confident` hold
 * only unflagged, graded rows of that band.
 *
 * The rank depends on JSON flags, so rows are ordered in PHP; a class is a
 * few hundred rows. Pages use a keyset cursor over the sort key, so a row
 * that changes between two pages is neither repeated nor skipped.
 */
final class ReviewQueue
{
    public const PAGE_SIZE = 100;

    public const MAX_PAGE_SIZE = 200;

    private const NO_NUMBER = 1000;

    /** @var Collection<int, Submission>|null */
    private ?Collection $submissions = null;

    /** @var array<int, int>|null student id => student number */
    private ?array $numbers = null;

    /** @var Collection<int, Response>|null */
    private ?Collection $responses = null;

    /** @var array<int, int>|null response id => open appeal id */
    private ?array $openAppeals = null;

    public function __construct(private readonly Assignment $assignment) {}

    /**
     * @return array{rows: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function page(?string $band, ?array $after, int $size = self::PAGE_SIZE): array
    {
        $rows = [];
        foreach ($this->responses() as $response) {
            if ($band !== null && self::tab($response) !== $band) {
                continue;
            }
            $key = $this->sortKey($response);
            if ($after !== null && ($key <=> $after) <= 0) {
                continue;
            }
            $rows[] = [$key, $response];
        }
        usort($rows, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $next = null;
        if (count($rows) > $size) {
            $rows = array_slice($rows, 0, $size);
            $next = self::encodeCursor($rows[$size - 1][0]);
        }

        return [
            'rows' => array_map(fn (array $r) => $this->row($r[1]), $rows),
            'next_cursor' => $next,
        ];
    }

    /**
     * Counts for the tabs, the missing-key banner (§13), per-student publish
     * progress and rescans of published pages waiting for confirm-replace.
     *
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        $counts = [];
        foreach (Response::BANDS as $band) {
            $counts[$band] = ['total' => 0, 'unreviewed' => 0];
        }
        $missingKey = 0;
        $bulk = 0;
        foreach ($this->responses() as $response) {
            $tab = self::tab($response);
            $counts[$tab]['total']++;
            if ($response->reviewed_at === null) {
                $counts[$tab]['unreviewed']++;
            }
            if ($response->reviewed_at === null && in_array($response->manualReason(), ScanGrader::KEY_REASONS, true)) {
                $missingKey++;
            }
            if ($this->bulkApprovable($response)) {
                $bulk++;
            }
        }

        return [
            'missing_ai_key_count' => $missingKey,
            'counts' => $counts,
            'bulk_approvable_count' => $bulk,
            'submissions' => $this->submissionSummaries(),
            'pending_confirm_scans' => $this->pendingScans(),
        ];
    }

    /** The tab a response sits on (see class doc). */
    public static function tab(Response $response): string
    {
        if ($response->grading_state === Response::STATE_MANUAL
            || ReviewFlags::flagged($response)
            || $response->priority_band === null
            || in_array($response->grading_state, Response::IN_PROGRESS_STATES, true)) {
            return ReviewPriority::BAND_CHECK;
        }

        return $response->priority_band;
    }

    /**
     * May "อนุมัติทั้งหมดที่มั่นใจ" approve it (DESIGN §9.5, §13): graded by
     * the AI, band confident, not flagged, not reviewed yet, no open appeal,
     * submission not published.
     */
    public static function approvable(Response $response, bool $appealOpen, bool $published): bool
    {
        return ! $published
            && ! $appealOpen
            && $response->reviewed_at === null
            && $response->grading_state === Response::STATE_SCORED
            && $response->priority_band === ReviewPriority::BAND_CONFIDENT
            && $response->ai_score !== null
            && $response->ai_understanding !== null
            && ! ReviewFlags::flagged($response);
    }

    /**
     * @return list<int|float>|null
     */
    public static function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }
        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $key = $json === false ? null : json_decode($json, true);
        if (! is_array($key) || ! array_is_list($key) || count($key) !== 5) {
            throw new \InvalidArgumentException('cursor');
        }
        foreach ($key as $part) {
            if (! is_int($part) && ! is_float($part)) {
                throw new \InvalidArgumentException('cursor');
            }
        }

        return $key;
    }

    /**
     * @param  list<int|float>  $key
     */
    private static function encodeCursor(array $key): string
    {
        return rtrim(strtr(base64_encode((string) json_encode($key)), '+/', '-_'), '=');
    }

    /**
     * @return list<int|float> rank, -priority, student number, question position, response id
     */
    private function sortKey(Response $response): array
    {
        $rank = match (true) {
            $response->grading_state === Response::STATE_MANUAL => 0,
            ReviewFlags::flagged($response) => 1,
            default => 2,
        };
        $priority = $response->review_priority === null ? 1.0 : -round((float) $response->review_priority, 4);

        return [
            $rank,
            $priority,
            $this->numbers()[$response->submission->student_id] ?? self::NO_NUMBER,
            (int) $response->question->position,
            $response->id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Response $response): array
    {
        $submission = $response->submission;
        $appealId = $this->openAppeals()[$response->id] ?? null;
        $flags = ReviewFlags::of($response, $appealId !== null);

        return [
            'id' => $response->id,
            'submission_id' => $response->submission_id,
            'submission_status' => $submission->status,
            'student' => $this->student($submission),
            'question_id' => $response->question_id,
            'question_position' => (int) $response->question->position,
            'question_type' => $response->question->type,
            'max_points' => (float) $response->question->max_points,
            'grading_state' => $response->grading_state,
            'manual_reason' => $response->manualReason(),
            'priority_band' => $response->priority_band,
            'review_priority' => $response->review_priority,
            'tab' => self::tab($response),
            'flags' => $flags,
            'suspicious' => in_array(ReviewFlags::SUSPICIOUS, $flags, true),
            'identity_mismatch' => in_array(ReviewFlags::IDENTITY_MISMATCH, $flags, true),
            'has_open_appeal' => $appealId !== null,
            'open_appeal_id' => $appealId,
            'bulk_approvable' => $this->bulkApprovable($response),
            'ai_score' => $response->ai_score,
            'ai_understanding' => $response->ai_understanding,
            'ai_error_types' => $response->ai_error_types,
            'final_score' => $response->final_score,
            'final_understanding' => $response->final_understanding,
            'final_error_types' => $response->final_error_types,
            'explanation_error' => $response->explanationError(),
            'has_crop' => $response->crop_path !== null,
            'has_final_crop' => $response->final_crop_path !== null,
            'reviewed_by' => $response->reviewed_by,
            'reviewed_at' => $response->reviewed_at?->toIso8601String(),
        ];
    }

    private function bulkApprovable(Response $response): bool
    {
        return self::approvable($response, isset($this->openAppeals()[$response->id]), $response->submission->isPublished());
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function submissionSummaries(): array
    {
        $byId = $this->responses()->groupBy('submission_id');
        $rows = [];
        foreach ($this->submissions() as $submission) {
            /** @var Collection<int, Response> $responses */
            $responses = $byId->get($submission->id, collect());
            $scores = $responses->map(fn (Response $r) => $r->effectiveScore());
            $current = $responses->isNotEmpty() && $scores->every(fn ($s) => $s !== null) ? round((float) $scores->sum(), 2) : null;
            $rows[] = [
                'id' => $submission->id,
                'status' => $submission->status,
                'student' => $this->student($submission),
                'response_count' => $responses->count(),
                'reviewed_count' => $responses->whereNotNull('reviewed_at')->count(),
                'open_appeal_count' => $responses->filter(fn (Response $r) => isset($this->openAppeals()[$r->id]))->count(),
                'total_score' => $submission->isPublished() ? $submission->total_score : $current,
                'published_at' => $submission->published_at?->toIso8601String(),
            ];
        }
        usort($rows, fn (array $a, array $b) => [$a['student']['student_number'] ?? self::NO_NUMBER, $a['id']] <=> [$b['student']['student_number'] ?? self::NO_NUMBER, $b['id']]);

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function pendingScans(): array
    {
        $bySubmission = $this->submissions()->keyBy('id');

        return Scan::query()
            ->where('state', Scan::STATE_PENDING_CONFIRM)
            ->whereIn('submission_id', $bySubmission->keys())
            ->orderBy('id')
            ->get()
            ->map(fn (Scan $scan) => [
                'scan_id' => $scan->id,
                'submission_id' => $scan->submission_id,
                'page_no' => $scan->page_no,
                'scanned_at' => $scan->scanned_at?->toIso8601String(),
                'student' => $this->student($bySubmission[$scan->submission_id]),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, student_number: int|null}
     */
    private function student(Submission $submission): array
    {
        return [
            'id' => $submission->student_id,
            'name' => (string) $submission->student?->name,
            'student_number' => $this->numbers()[$submission->student_id] ?? null,
        ];
    }

    /** @return Collection<int, Submission> */
    private function submissions(): Collection
    {
        return $this->submissions ??= Submission::query()
            ->where('assignment_id', $this->assignment->id)
            ->with('student:id,name')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, int> */
    private function numbers(): array
    {
        return $this->numbers ??= ClassroomStudent::query()
            ->where('classroom_id', $this->assignment->classroom_id)
            ->pluck('student_number', 'student_id')
            ->map(fn ($n) => (int) $n)
            ->all();
    }

    /** @return Collection<int, Response> */
    private function responses(): Collection
    {
        if ($this->responses !== null) {
            return $this->responses;
        }
        $submissions = $this->submissions()->keyBy('id');
        $responses = Response::query()
            ->whereIn('submission_id', $submissions->keys())
            ->with('question:id,position,type,max_points')
            ->get();
        foreach ($responses as $response) {
            $response->setRelation('submission', $submissions[$response->submission_id]);
        }

        return $this->responses = $responses;
    }

    /** @return array<int, int> */
    private function openAppeals(): array
    {
        return $this->openAppeals ??= Appeal::query()
            ->where('status', Appeal::STATUS_OPEN)
            ->whereIn('response_id', $this->responses()->modelKeys())
            ->pluck('id', 'response_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
