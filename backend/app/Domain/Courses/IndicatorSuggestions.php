<?php

namespace App\Domain\Courses;

use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Exceptions\ApiException;
use App\Http\Resources\SkillResource;
use App\Jobs\SuggestIndicatorsJob;
use App\Models\Assignment;
use App\Models\IndicatorSuggestion;
use App\Models\LessonPlan;
use App\Models\Question;
use App\Models\Skill;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Indicator suggestions of an assignment (DESIGN §20.3, §20.7):
 *
 * - request(): POST /assignments/{id}/indicator-suggestions. The assignment
 *   must be linked to a lesson plan (422 lesson_plan_required) that has
 *   indicators (422 lesson_plan_no_indicators) and have questions (422
 *   no_questions); the classroom owner's key is needed (422
 *   ai_key_missing). Queues SuggestIndicatorsJob (202) unless one is
 *   already queued for less than STALE_MINUTES.
 * - autoOnApproval(): the same, silently, when the teacher approves the
 *   answer key of an assignment linked to a plan and some question has no
 *   indicator yet ("หรือระบบเสนอให้ตอนอนุมัติเฉลย").
 * - process(): the job's work: IndicatorSuggester, then the rows of
 *   indicator_suggestions for every question are replaced.
 * - payload(): GET …/indicator-suggestions: per question its confirmed
 *   indicators (question_skill) and the suggestions still in the plan,
 *   plus unmapped_question_count and the warning of §20.3 (never a block).
 *
 * The state of the latest request (queued, done, failed) lives in the
 * cache (database store in production), not in a table: it only tells the
 * polling app whether to wait, and the suggestions themselves are rows.
 */
final class IndicatorSuggestions
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    /** A request still `queued` after this long is presumed lost and queued again. */
    private const STALE_MINUTES = 15;

    private const STATE_TTL_DAYS = 7;

    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly IndicatorSuggester $suggester,
    ) {}

    /**
     * @return array<string, mixed> the state (see state())
     *
     * @throws ApiException
     */
    public function request(Assignment $assignment): array
    {
        $plan = $this->planOf($assignment);
        if ($plan === null) {
            throw new ApiException(
                'ผูกการบ้านนี้กับแผนการสอนก่อน แล้วจึงให้ AI เสนอตัวชี้วัด',
                'lesson_plan_required',
                422,
                ['lesson_plan_id' => ['กรุณาเลือกแผนการสอน']],
            );
        }
        if ($plan->indicators->isEmpty()) {
            throw new ApiException('แผนการสอนนี้ยังไม่มีตัวชี้วัด เพิ่มตัวชี้วัดของแผนก่อน', 'lesson_plan_no_indicators', 422);
        }
        if (! Question::query()->where('assignment_id', $assignment->id)->exists()) {
            throw new ApiException('การบ้านนี้ยังไม่มีข้อ', 'no_questions', 422);
        }
        if ($this->keys->forTeacher($assignment->classroom?->teacher_id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        if (! $this->isRunning($assignment->id)) {
            $this->queue($assignment->id);
        }

        return $this->state($assignment->id);
    }

    /** Suggest on answer-key approval when a question still has no indicator; never throws. */
    public function autoOnApproval(Assignment $assignment): void
    {
        try {
            $plan = $this->planOf($assignment);
            if ($plan === null || $plan->indicators->isEmpty() || $this->isRunning($assignment->id)) {
                return;
            }
            $unmapped = Question::query()->where('assignment_id', $assignment->id)->whereDoesntHave('skills')->exists();
            // Suggested before: rows, or a finished round that found nothing fitting (no
            // rows, cache status done): approving again must not pay for the same call.
            $suggested = $this->state($assignment->id)['status'] === self::STATUS_DONE
                || IndicatorSuggestion::query()->whereIn('question_id', Question::query()->select('id')->where('assignment_id', $assignment->id))->exists();
            if (! $unmapped || $suggested || $this->keys->forTeacher($assignment->classroom?->teacher_id) === null) {
                return;
            }
            $this->queue($assignment->id);
        } catch (Throwable $e) {
            Log::warning('indicator_suggest.auto_failed', ['assignment_id' => $assignment->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The job's work. Transport errors are rethrown for the queue's retry
     * until the last attempt; everything else ends as `failed`.
     *
     * @throws GeminiException
     */
    public function process(int $assignmentId, bool $lastAttempt): void
    {
        $assignment = Assignment::query()->with(['classroom', 'subject'])->find($assignmentId);
        if ($assignment === null) {
            Cache::forget(self::cacheKey($assignmentId));

            return;
        }
        $plan = $this->planOf($assignment);
        if ($plan === null || $plan->indicators->isEmpty()) {
            $this->fail($assignmentId, 'lesson_plan_required', 'การบ้านนี้ไม่ได้ผูกแผนการสอนที่มีตัวชี้วัดแล้ว');

            return;
        }
        $key = $this->keys->forTeacher($assignment->classroom?->teacher_id);
        if ($key === null) {
            $this->fail($assignmentId, 'ai_key_missing', 'ยังไม่มี Gemini API key ให้ใช้');

            return;
        }

        $questions = Question::query()->where('assignment_id', $assignment->id)->orderBy('position')->get()->all();
        $indicators = self::sortedIndicators($plan);
        try {
            $result = $this->suggester->suggest($assignment, $plan, $questions, $indicators, $key);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR && ! $lastAttempt) {
                throw $e;
            }
            Log::warning('indicator_suggest.failed', ['assignment_id' => $assignment->id, 'status' => $e->status]);
            $this->fail($assignmentId, $e->status === GeminiException::KEY_INVALID ? 'ai_key_invalid' : 'ai_failed', match ($e->status) {
                GeminiException::KEY_INVALID => 'Google ไม่รับ Gemini API key นี้ ตรวจ key ที่หน้าตั้งค่า',
                default => 'AI เสนอตัวชี้วัดไม่สำเร็จ ลองอีกครั้ง หรือเลือกตัวชี้วัดเอง',
            });

            return;
        }

        DB::transaction(function () use ($questions, $result) {
            $ids = array_map(fn (Question $q) => $q->id, $questions);
            IndicatorSuggestion::query()->whereIn('question_id', $ids)->delete();
            $now = now();
            $rows = [];
            foreach ($result['suggestions'] as $questionId => $picked) {
                foreach ($picked as $row) {
                    $rows[] = ['question_id' => $questionId, 'skill_id' => $row['skill_id'], 'reason_th' => $row['reason_th'], 'created_at' => $now];
                }
            }
            foreach (array_chunk($rows, 200) as $chunk) {
                IndicatorSuggestion::query()->insert($chunk);
            }
        });

        $state = $this->state($assignmentId);
        $this->remember($assignmentId, [
            'status' => self::STATUS_DONE,
            'requested_at' => $state['requested_at'],
            'finished_at' => now()->toIso8601String(),
            'error' => null,
            'suggested_question_count' => count($result['suggestions']),
            'dropped_code_count' => $result['dropped'],
        ]);
    }

    /** The job gave up (last try failed or timed out). */
    public function giveUp(int $assignmentId): void
    {
        if ($this->state($assignmentId)['status'] === self::STATUS_QUEUED) {
            $this->fail($assignmentId, 'ai_failed', 'AI เสนอตัวชี้วัดไม่สำเร็จ ลองอีกครั้ง หรือเลือกตัวชี้วัดเอง');
        }
    }

    /**
     * {status: null|queued|done|failed, requested_at, finished_at,
     *  error: {code, message}|null, suggested_question_count, dropped_code_count}
     *
     * @return array{status: string|null, requested_at: string|null, finished_at: string|null, error: array{code: string, message: string}|null, suggested_question_count: int|null, dropped_code_count: int|null}
     */
    public function state(int $assignmentId): array
    {
        $state = Cache::get(self::cacheKey($assignmentId));

        return [
            'status' => $state['status'] ?? null,
            'requested_at' => $state['requested_at'] ?? null,
            'finished_at' => $state['finished_at'] ?? null,
            'error' => $state['error'] ?? null,
            'suggested_question_count' => $state['suggested_question_count'] ?? null,
            'dropped_code_count' => $state['dropped_code_count'] ?? null,
        ];
    }

    /**
     * GET /assignments/{id}/indicator-suggestions.
     *
     * @return array<string, mixed>
     */
    public function payload(Assignment $assignment): array
    {
        $plan = $this->planOf($assignment);
        $planSkillIds = $plan === null ? [] : $plan->indicators->modelKeys();
        $questions = Question::query()
            ->where('assignment_id', $assignment->id)
            ->orderBy('position')
            ->with(['skills', 'indicatorSuggestions.skill'])
            ->get();

        $unmapped = $questions->filter(fn (Question $q) => $q->skills->isEmpty())->count();

        return [
            'assignment_id' => $assignment->id,
            'lesson_plan' => $plan === null ? null : ['id' => $plan->id, 'title' => $plan->title, 'unit_id' => $plan->unit_id],
            'plan_indicators' => $plan === null ? [] : array_map(fn (Skill $s) => SkillResource::indicator($s), self::sortedIndicators($plan)),
            ...$this->state($assignment->id),
            'questions' => $questions->map(fn (Question $q) => [
                'question_id' => $q->id,
                'position' => $q->position,
                'type' => $q->type,
                'prompt_text' => $q->prompt_text,
                'skill_ids' => $q->skills->modelKeys(),
                'skills' => $q->skills->map(fn (Skill $s) => SkillResource::indicator($s))->values()->all(),
                // Only suggestions still in the plan (the plan's indicators may have changed since).
                'suggestions' => $q->indicatorSuggestions
                    ->filter(fn (IndicatorSuggestion $s) => $s->skill !== null && in_array($s->skill_id, $planSkillIds, true))
                    ->sortBy(fn (IndicatorSuggestion $s) => $s->skill->code, SORT_NATURAL)
                    ->map(fn (IndicatorSuggestion $s) => [
                        'skill' => SkillResource::indicator($s->skill),
                        'reason_th' => $s->reason_th,
                    ])->values()->all(),
            ])->all(),
            'unmapped_question_count' => $unmapped,
            'unmapped_warning' => self::unmappedWarning($unmapped),
        ];
    }

    /** DESIGN §20.3: a warning, never a block. */
    public static function unmappedWarning(int $unmapped): ?string
    {
        return $unmapped > 0 ? "มี {$unmapped} ข้อยังไม่ผูกตัวชี้วัด คะแนนข้อเหล่านี้จะไม่นับในกราฟ" : null;
    }

    private function planOf(Assignment $assignment): ?LessonPlan
    {
        if ($assignment->lesson_plan_id === null) {
            return null;
        }

        return LessonPlan::query()->with(['indicators', 'course.subject'])->find($assignment->lesson_plan_id);
    }

    /** @return list<Skill> */
    private static function sortedIndicators(LessonPlan $plan): array
    {
        return $plan->indicators->sortBy('code', SORT_NATURAL)->values()->all();
    }

    private function isRunning(int $assignmentId): bool
    {
        $state = $this->state($assignmentId);

        return $state['status'] === self::STATUS_QUEUED
            && $state['requested_at'] !== null
            && Carbon::parse($state['requested_at'])->gt(now()->subMinutes(self::STALE_MINUTES));
    }

    private function queue(int $assignmentId): void
    {
        $this->remember($assignmentId, [
            'status' => self::STATUS_QUEUED,
            'requested_at' => now()->toIso8601String(),
            'finished_at' => null,
            'error' => null,
            'suggested_question_count' => null,
            'dropped_code_count' => null,
        ]);
        SuggestIndicatorsJob::dispatch($assignmentId);
    }

    private function fail(int $assignmentId, string $code, string $message): void
    {
        $state = $this->state($assignmentId);
        $this->remember($assignmentId, [
            'status' => self::STATUS_FAILED,
            'requested_at' => $state['requested_at'],
            'finished_at' => now()->toIso8601String(),
            'error' => ['code' => $code, 'message' => $message],
            'suggested_question_count' => null,
            'dropped_code_count' => null,
        ]);
    }

    /** @param array<string, mixed> $state */
    private function remember(int $assignmentId, array $state): void
    {
        Cache::put(self::cacheKey($assignmentId), $state, now()->addDays(self::STATE_TTL_DAYS));
    }

    private static function cacheKey(int $assignmentId): string
    {
        return 'indicator-suggest:'.$assignmentId;
    }
}
