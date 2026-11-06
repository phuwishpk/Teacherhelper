<?php

namespace App\Domain\Gemini;

use App\Exceptions\ApiException;

/**
 * The API error of a failed AI call answered in the same request (DESIGN
 * §36): the old `code` stays (ai_key_invalid 422, ai_unavailable 502) so
 * older apps keep working, and the body adds `ai_error_code` and, when the
 * call was logged, `ai_call_id`. The message says what happened in Thai.
 */
final class AiErrors
{
    /** $what: what failed, e.g. "AI เขียนคำอธิบายไม่สำเร็จ". */
    public static function fromOutcome(CallOutcome $outcome, string $what): ApiException
    {
        $code = $outcome->errorCode ?? AiErrorCode::ERROR;

        return self::make($code, $outcome->aiCallId, $what);
    }

    public static function fromException(GeminiException $e, string $what): ApiException
    {
        return self::make($e->code(), $e->aiCallId, $what);
    }

    public static function make(string $code, ?int $aiCallId, string $what): ApiException
    {
        $extra = array_filter(['ai_error_code' => $code, 'ai_call_id' => $aiCallId], fn ($v) => $v !== null);

        return match ($code) {
            AiErrorCode::KEY_MISSING => new ApiException(AiErrorCode::message($code), 'ai_key_missing', 422, [], $extra),
            AiErrorCode::KEY_INVALID => new ApiException(AiErrorCode::message($code), 'ai_key_invalid', 422, [], $extra),
            default => new ApiException($what.': '.AiErrorCode::message($code), 'ai_unavailable', 502, [], $extra),
        };
    }
}
