<?php

namespace App\Jobs;

use App\Domain\Assignments\RubricService;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\RubricDrafter;
use App\Domain\Gemini\RubricDraftRequest;
use App\Models\Question;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * POST /questions/{id}/rubric/draft (DESIGN §9.3, §10.4): asks Gemini for a
 * draft rubric (RubricDrafter: prompt file, schema, server-side rubric
 * checks, one retry of invalid output, ai_calls) and stores it as
 * rubric_status `draft` (source `ai`). The app polls the assignment until
 * the draft appears.
 *
 * Transport errors retry with backoff. Output that is still invalid after
 * the gateway's retry, a rejected key or no key at all end the job: the
 * teacher can ask again or write the rubric by hand.
 */
class DraftRubricJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public int $timeout = 90;

    public function __construct(public readonly int $questionId)
    {
        $this->onQueue('default');
    }

    public function handle(RubricDrafter $drafter, GeminiKeyResolver $keys, RubricService $rubrics): void
    {
        $question = Question::query()->find($this->questionId);
        if ($question === null || ! $question->needsRubric()) {
            return;
        }

        $request = RubricDraftRequest::fromQuestion($question);
        $key = $keys->forTeacher($request->teacherId);
        if ($key === null) {
            Log::warning('rubric_draft.no_ai_key', ['question_id' => $question->id]);

            return;
        }

        try {
            $draft = $drafter->draft($request, $key);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR) {
                throw $e; // transient: the queue retries with backoff
            }
            Log::warning('rubric_draft.failed', ['question_id' => $question->id, 'status' => $e->status]);
            $this->fail($e);

            return;
        }

        $rubrics->applyDraft($question, $draft);
    }
}
