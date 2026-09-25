<?php

namespace App\Jobs;

use App\Domain\Assignments\RubricService;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\RubricDraftRequest;
use App\Models\Question;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * POST /questions/{id}/rubric/draft (DESIGN §9.3, §10.4): asks Gemini for a
 * draft rubric and stores it as rubric_status `draft` (source `ai`). The app
 * polls the assignment until the draft appears.
 */
class DraftRubricJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public int $timeout = 45;

    public function __construct(public readonly int $questionId)
    {
        $this->onQueue('default');
    }

    public function handle(GeminiClient $gemini, RubricService $rubrics): void
    {
        $question = Question::query()->find($this->questionId);
        if ($question === null || ! $question->needsRubric()) {
            return;
        }

        $draft = $gemini->draftRubric(RubricDraftRequest::fromQuestion($question));
        $draft->validateFor($question->type, $question->max_points);

        $rubrics->applyDraft($question, $draft);
    }
}
