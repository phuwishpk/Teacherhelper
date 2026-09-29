<?php

namespace App\Domain\Gemini\Calibration;

use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiReply;

/**
 * Wraps the real client with a hard cap on requests (the gateway's retries
 * included): once the calibration run has spent its budget, every further
 * request fails locally with "call budget reached" and costs nothing.
 */
final class BudgetedClient implements GeminiClient
{
    private int $sent = 0;

    public function __construct(
        private readonly GeminiClient $inner,
        private readonly int $budget,
    ) {}

    public function model(): string
    {
        return $this->inner->model();
    }

    public function sent(): int
    {
        return $this->sent;
    }

    public function generate(array $requests, #[\SensitiveParameter] string $apiKey): array
    {
        $allowed = array_slice($requests, 0, max(0, $this->budget - $this->sent), true);
        $this->sent += count($allowed);
        $replies = $allowed === [] ? [] : $this->inner->generate($allowed, $apiKey);
        foreach (array_diff_key($requests, $allowed) as $key => $request) {
            $replies[$key] = GeminiReply::error('call budget reached (--max-calls)');
        }

        return $replies;
    }

    public function listModels(#[\SensitiveParameter] string $apiKey): array
    {
        return $this->inner->listModels($apiKey);
    }
}
