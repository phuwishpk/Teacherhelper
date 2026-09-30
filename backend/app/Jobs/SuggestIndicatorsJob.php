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
 * again or pick indicators by hand. Carries the id only.
 */
class SuggestIndicatorsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 180];

    public int $timeout = 90;

    public function __construct(public readonly int $assignmentId)
    {
        $this->onQueue('default');
    }

    public function handle(IndicatorSuggestions $suggestions): void
    {
        $suggestions->process($this->assignmentId, lastAttempt: $this->attempts() >= $this->tries);
    }

    public function failed(?Throwable $exception): void
    {
        app(IndicatorSuggestions::class)->giveUp($this->assignmentId);
    }
}
