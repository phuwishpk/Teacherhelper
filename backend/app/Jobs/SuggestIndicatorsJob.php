<?php

namespace App\Jobs;

use App\Domain\Courses\IndicatorSuggestions;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * POST /assignments/{id}/indicator-suggestions (DESIGN §20.3): Gemini
 * suggests indicators of the linked lesson plan for every question
 * (IndicatorSuggestions::process). Transport errors retry with backoff;
 * after the last try the request is `failed` and the teacher can ask
 * again or pick indicators by hand. Carries the id and the teacher's
 * guidance (a short text they typed, never student data).
 */
class SuggestIndicatorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public int $timeout = 90;

    /**
     * $guidance / $guidanceBy: the teacher's guidance to the AI of this
     * request and its author (DESIGN §21.12); null for the suggestion made
     * on answer-key approval.
     */
    public function __construct(
        public readonly int $assignmentId,
        public readonly ?string $guidance = null,
        public readonly ?int $guidanceBy = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(IndicatorSuggestions $suggestions): void
    {
        $suggestions->process($this->assignmentId, lastAttempt: $this->attempts() >= $this->tries, guidance: $this->guidance, guidanceBy: $this->guidanceBy);
    }

    public function failed(?Throwable $exception): void
    {
        app(IndicatorSuggestions::class)->giveUp($this->assignmentId);
    }
}
