<?php

namespace App\Domain\Courses;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Exceptions\ApiException;
use App\Http\Resources\SkillResource;
use App\Jobs\SuggestIndicatorsJob;
use App\Models\Assignment;
use App\Models\IndicatorSuggestion;
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
 * - request(): POST /assignments/{id}/indicator-suggestions. The indicators
 *   come from IndicatorScope: the linked lesson plan's, or for an exam
 *   without a plan the course's (§22.13). Homework must be linked to a
 *   lesson plan (422 lesson_plan_required); the list must not be empty
 *   (422 lesson_plan_no_indicators) and there must be questions (422
 *   no_questions); the classroom owner's key is needed (422
 *   ai_key_missing). Queues SuggestIndicatorsJob (202) unless one is
 *   already queued for less than STALE_MINUTES.
 * - autoOnApproval(): the same, silently, when the teacher approves the
 *   answer key of an assignment with indicators to pick from (a plan, or
 *   an exam's course) and some question has no indicator yet ("หรือระบบเสนอให้ตอนอนุมัติเฉลย").
 * - process(): the job's work: IndicatorSuggester, then the rows of
 *   indicator_suggestions for every question are replaced.
 * - payload(): GET …/indicator-suggestions: per question its confirmed
 *   indicators (question_skill) and the suggestions still in the plan (or
 *   the exam's course: indicator_source, course),
 *   plus unmapped_question_count and the warning of §20.3 (never a block).
 *
 * The state of the latest request (queued, done, failed) lives in the
 * cache (database store in production), not in a table: it only tells the
 * polling app whether to wait, and the suggestions themselves are rows.
 *
 * guidance (§21.12): the teacher's guidance of the latest round, kept in
 * the state (so the app can show and prefill it) and carried by the job.
 * While a round is queued a new request queues nothing and answers the
 * running round's state, guidance included; once it finished, a request
 * with any guidance starts a new round.
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
    public function request(Assignment $assignment, ?string $guidance = null, ?int $guidanceBy = null): array
    {
        $scope = IndicatorScope::of($assignment);
        if ($scope === null) {
            // An exam without a plan uses its course's indicators (§22.13), so only one with neither lands here.
            throw $assignment->isExam()
                ? new ApiException(
                    'ผูกข้อสอบนี้กับรายวิชาหรือแผนการสอนที่หน้าตั้งค่าข้อสอบก่อน แล้วจึงให้ AI เสนอตัวชี้วัด',
                    'lesson_plan_required',
                    422,
                    ['course_id' => ['กรุณาเลือกรายวิชา']],
                )
                : new ApiException(
                    'ผูกการบ้านนี้กับแผนการสอนก่อน แล้วจึงให้ AI เสนอตัวชี้วัด',
                    'lesson_plan_required',
                    422,
                    ['lesson_plan_id' => ['กรุณาเลือกแผนการสอน']],
                );
        }
        if ($scope->isEmpty()) {
            throw new ApiException(
                $scope->plan !== null
                    ? 'แผนการสอนนี้ยังไม่มีตัวชี้วัด เพิ่มตัวชี้วัดของแผนก่อน'
                    : 'รายวิชานี้ยังไม่มีตัวชี้วัด เพิ่มตัวชี้วัดของรายวิชา หน่วย หรือแผนการสอนก่อน',
                'lesson_plan_no_indicators',
                422,
            );
        }
        if (! Question::query()->where('assignment_id', $assignment->id)->exists()) {
            throw new ApiException($assignment->isExam() ? 'ข้อสอบนี้ยังไม่มีข้อ' : 'การบ้านนี้ยังไม่มีข้อ', 'no_questions', 422);
        }
        if ($this->keys->forTeacher(ClassroomAccess::managerId($assignment)) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }

        if (! $this->isRunning($assignment->id)) {
            $this->queue($assignment->id, $guidance, $guidanceBy);
        }

        return $this->state($assignment->id);
    }

    /** Suggest on answer-key approval when a question still has no indicator; never throws. */
    public function autoOnApproval(Assignment $assignment): void
    {
        try {
            $scope = IndicatorScope::of($assignment);
            if ($scope === null || $scope->isEmpty() || $this->isRunning($assignment->id)) {
                return;
            }
            $unmapped = Question::query()->where('assignment_id', $assignment->id)->whereDoesntHave('skills')->exists();
            // Suggested before: rows, or a finished round that found nothing fitting (no
            // rows, cache status done): approving again must not pay for the same call.
            $suggested = $this->state($assignment->id)['status'] === self::STATUS_DONE
                || IndicatorSuggestion::query()->whereIn('question_id', Question::query()->select('id')->where('assignment_id', $assignment->id))->exists();
            if (! $unmapped || $suggested || $this->keys->forTeacher(ClassroomAccess::managerId($assignment)) === null) {
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
    public function process(int $assignmentId, bool $lastAttempt, ?string $guidance = null, ?int $guidanceBy = null): void
    {
        $assignment = Assignment::query()->with(['classroom', 'subject'])->find($assignmentId);
        if ($assignment === null) {
            Cache::forget(self::cacheKey($assignmentId));

            return;
        }
        $scope = IndicatorScope::of($assignment);
        if ($scope === null || $scope->isEmpty()) {
            $this->fail($assignmentId, 'lesson_plan_required', $assignment->isExam()
                ? 'ข้อสอบนี้ไม่มีตัวชี้วัดของแผนหรือรายวิชาให้เลือกแล้ว'
                : 'การบ้านนี้ไม่ได้ผูกแผนการสอนที่มีตัวชี้วัดแล้ว');

            return;
        }
        $key = $this->keys->forTeacher(ClassroomAccess::managerId($assignment));
        if ($key === null) {
            $this->fail($assignmentId, 'ai_key_missing', 'ยังไม่มี Gemini API key ให้ใช้');

            return;
        }

        $questions = Question::query()->where('assignment_id', $assignment->id)->orderBy('position')->with('options')->get()->all();
        try {
            $result = $this->suggester->suggest($assignment, $scope, $questions, $key, $guidance, $guidanceBy);
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
            'guidance' => $state['guidance'],
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
     *  error: {code, message}|null, suggested_question_count, dropped_code_count,
     *  guidance: the teacher's guidance of that round|null}
     *
     * @return array{status: string|null, requested_at: string|null, finished_at: string|null, error: array{code: string, message: string}|null, suggested_question_count: int|null, dropped_code_count: int|null, guidance: string|null}
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
            'guidance' => $state['guidance'] ?? null,
        ];
    }

    /**
     * GET /assignments/{id}/indicator-suggestions.
     *
     * @return array<string, mixed>
     */
    public function payload(Assignment $assignment): array
    {
        $scope = IndicatorScope::of($assignment);
        $plan = $scope?->plan;
        $planSkillIds = $scope === null ? [] : $scope->skillIds();
        $questions = Question::query()
            ->where('assignment_id', $assignment->id)
            ->orderBy('position')
            ->with(['skills', 'indicatorSuggestions.skill'])
            ->get();

        $unmapped = $questions->filter(fn (Question $q) => $q->skills->isEmpty())->count();

        return [
            'assignment_id' => $assignment->id,
            'lesson_plan' => $plan === null ? null : ['id' => $plan->id, 'title' => $plan->title, 'unit_id' => $plan->unit_id],
            // lesson_plan | course (an exam without a plan, §22.13) | null; plan_indicators holds that list.
            'indicator_source' => $scope?->source,
            'course' => $scope?->source === IndicatorScope::SOURCE_COURSE && $scope->course !== null
                ? ['id' => $scope->course->id, 'code' => $scope->course->code, 'name' => $scope->course->name]
                : null,
            'plan_indicators' => $scope === null ? [] : array_map(fn (Skill $s) => SkillResource::indicator($s), $scope->indicators),
            ...$this->state($assignment->id),
            'questions' => $questions->map(fn (Question $q) => [
                'question_id' => $q->id,
                'position' => $q->position,
                'type' => $q->type,
                'prompt_text' => $q->prompt_text,
                'skill_ids' => $q->skills->modelKeys(),
                'skills' => $q->skills->map(fn (Skill $s) => SkillResource::indicator($s))->values()->all(),
                // Only suggestions still in the plan (or the exam's course; its indicators may have changed since).
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

    private function isRunning(int $assignmentId): bool
    {
        $state = $this->state($assignmentId);

        return $state['status'] === self::STATUS_QUEUED
            && $state['requested_at'] !== null
            && Carbon::parse($state['requested_at'])->gt(now()->subMinutes(self::STALE_MINUTES));
    }

    private function queue(int $assignmentId, ?string $guidance = null, ?int $guidanceBy = null): void
    {
        $this->remember($assignmentId, [
            'status' => self::STATUS_QUEUED,
            'requested_at' => now()->toIso8601String(),
            'finished_at' => null,
            'error' => null,
            'suggested_question_count' => null,
            'dropped_code_count' => null,
            'guidance' => $guidance,
        ]);
        SuggestIndicatorsJob::dispatch($assignmentId, $guidance, $guidanceBy);
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
            'guidance' => $state['guidance'],
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
