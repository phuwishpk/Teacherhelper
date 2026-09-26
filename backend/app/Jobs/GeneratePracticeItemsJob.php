<?php

namespace App\Jobs;

use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PracticeGenerator;
use App\Domain\Gemini\PracticeGenRequest;
use App\Domain\Practice\PracticeBank;
use App\Models\School;
use App\Models\Skill;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * POST /skills/{id}/practice-items/generate (DESIGN §9.6, §10.6, §14.1):
 * asks Gemini for `count` practice items of the skill (PracticeGenerator:
 * prompt file, schema, item checks, one retry of invalid output, ai_calls)
 * and stores them as drafts of the school (source ai). The teacher's key
 * pays (GeminiKeyResolver), the app lists the bank again to see them.
 *
 * Transport errors retry with backoff. Output still invalid after the
 * gateway's retry, a rejected key or no key at all end the job with a log
 * line: the teacher can ask again or write items by hand.
 */
class GeneratePracticeItemsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public int $timeout = 90;

    public function __construct(
        public readonly int $skillId,
        public readonly int $schoolId,
        public readonly int $teacherId,
        public readonly int $count,
    ) {
        $this->onQueue('default');
    }

    public function handle(PracticeGenerator $generator, GeminiKeyResolver $keys, PracticeBank $bank): void
    {
        $skill = Skill::query()->find($this->skillId);
        $school = School::query()->find($this->schoolId);
        if ($skill === null || $school === null) {
            return;
        }

        $key = $keys->forTeacher($this->teacherId);
        if ($key === null) {
            Log::warning('practice_gen.no_ai_key', ['skill_id' => $skill->id, 'teacher_id' => $this->teacherId]);

            return;
        }

        try {
            $draft = $generator->generate(PracticeGenRequest::forSkill($skill, $school, $this->teacherId, $this->count), $key);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR) {
                throw $e; // transient: the queue retries with backoff
            }
            Log::warning('practice_gen.failed', ['skill_id' => $skill->id, 'status' => $e->status]);
            $this->fail($e);

            return;
        }

        $items = $bank->storeDrafts($school->id, $skill->id, $draft);
        Log::info('practice_gen.stored', ['skill_id' => $skill->id, 'school_id' => $school->id, 'items' => count($items)]);
    }
}
