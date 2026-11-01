<?php

namespace App\Domain\Gemini;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The last failure of a queued AI job that has no row of its own to keep
 * it (DESIGN §36.1): the rubric draft of a question, the practice items a
 * teacher asked for one indicator. Kept in the cache (database store in
 * production) for TTL_DAYS, cleared when the job runs again, and shown
 * with its code and ai_calls id where the teacher waits for the result.
 */
final class AiJobFailures
{
    public const TTL_DAYS = 7;

    public const RUBRIC = 'rubric';

    public const PRACTICE = 'practice';

    public static function record(string $scope, string $id, ?string $code, ?int $callId, string $message): void
    {
        Cache::put(self::key($scope, $id), [
            'ai_error_code' => $code ?? AiErrorCode::ERROR,
            'ai_call_id' => $callId,
            'message' => $message,
            'at' => now()->toIso8601String(),
        ], now()->addDays(self::TTL_DAYS));
    }

    /** A job that ended with an exception: its code, or AI-ERROR. */
    public static function recordThrowable(string $scope, string $id, Throwable $e, string $message): void
    {
        $gemini = $e instanceof GeminiException ? $e : null;
        self::record($scope, $id, $gemini?->code() ?? AiErrorCode::ERROR, $gemini?->aiCallId, $message);
    }

    /** @return array{ai_error_code: string, ai_call_id: int|null, message: string, at: string}|null */
    public static function get(string $scope, string $id): ?array
    {
        $value = Cache::get(self::key($scope, $id));

        return is_array($value) ? $value : null;
    }

    public static function forget(string $scope, string $id): void
    {
        Cache::forget(self::key($scope, $id));
    }

    private static function key(string $scope, string $id): string
    {
        return "ai-job-failure:{$scope}:{$id}";
    }
}
