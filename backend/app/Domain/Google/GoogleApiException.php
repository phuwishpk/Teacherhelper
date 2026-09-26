<?php

namespace App\Domain\Google;

use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * A failed call to Google (OAuth, Classroom or Drive), typed by what the
 * caller has to do about it. The message carries the HTTP status and
 * Google's own error code/message only, never a token or a request body.
 *
 *   not_configured             GOOGLE_OAUTH_CLIENT_ID / _SECRET are not set
 *   invalid_grant              the refresh token was revoked or expired (7 days
 *                              in Testing mode, §18.5), or the auth code was
 *                              used / expired: the teacher must connect again
 *   scope_missing              the token lacks a scope we need
 *   project_permission_denied  Classroom: the courseWork was not created by this
 *                              project, so grades/returns are refused (§18.2)
 *   permission_denied          any other 403 (not a teacher of the course, ...)
 *   api_disabled               the Classroom/Drive API is off in the Cloud
 *                              project or for the teacher's Workspace domain
 *   not_found                  course, courseWork, submission or file is gone
 *   failed_precondition        Classroom refused the state change (400
 *                              FAILED_PRECONDITION, e.g. returning work that
 *                              was never handed in)
 *   bad_request                any other 4xx
 *   unavailable                network error, 429 or 5xx: worth retrying
 */
final class GoogleApiException extends RuntimeException implements ShouldntReport
{
    public const NOT_CONFIGURED = 'not_configured';

    public const INVALID_GRANT = 'invalid_grant';

    public const SCOPE_MISSING = 'scope_missing';

    public const PROJECT_PERMISSION_DENIED = 'project_permission_denied';

    public const PERMISSION_DENIED = 'permission_denied';

    public const API_DISABLED = 'api_disabled';

    public const NOT_FOUND = 'not_found';

    public const FAILED_PRECONDITION = 'failed_precondition';

    public const BAD_REQUEST = 'bad_request';

    public const UNAVAILABLE = 'unavailable';

    public function __construct(
        public readonly string $kind,
        string $message,
        public readonly ?int $httpStatus = null,
        public readonly ?string $googleMessage = null,
    ) {
        parent::__construct($message);
    }

    /** Worth trying again later (queue retries). */
    public function isTransient(): bool
    {
        return $this->kind === self::UNAVAILABLE;
    }

    /** Only a new POST /google/connect fixes it. */
    public function needsReconnect(): bool
    {
        return in_array($this->kind, [self::INVALID_GRANT, self::SCOPE_MISSING], true);
    }

    /**
     * Maps an error answer of a Google REST API (Classroom v1, Drive v3):
     * {"error": {"code", "message", "status", "details": [{"reason"}], "errors": [{"reason"}]}}.
     * Classroom puts its own reason at the start of the message, e.g.
     * "@ProjectPermissionDenied The Developer Console project is not permitted ...".
     */
    public static function fromApiResponse(Response $response, string $what): self
    {
        $status = $response->status();
        $error = $response->json('error');
        $message = is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null;
        $googleStatus = is_array($error) && is_string($error['status'] ?? null) ? $error['status'] : null;
        $reasons = [];
        if (is_array($error)) {
            foreach (['details', 'errors'] as $list) {
                foreach (is_array($error[$list] ?? null) ? $error[$list] : [] as $item) {
                    if (is_array($item) && is_string($item['reason'] ?? null)) {
                        $reasons[] = $item['reason'];
                    }
                }
            }
        }
        $text = $message ?? '';

        $kind = match (true) {
            $status === 401 => self::INVALID_GRANT,
            $status === 429 || $status >= 500 => self::UNAVAILABLE,
            str_contains($text, 'ProjectPermissionDenied') => self::PROJECT_PERMISSION_DENIED,
            in_array('ACCESS_TOKEN_SCOPE_INSUFFICIENT', $reasons, true)
                || in_array('insufficientPermissions', $reasons, true)
                || str_contains(strtolower($text), 'insufficient authentication scopes') => self::SCOPE_MISSING,
            in_array('SERVICE_DISABLED', $reasons, true)
                || in_array('accessNotConfigured', $reasons, true)
                || str_contains($text, 'ClassroomApiDisabled') => self::API_DISABLED,
            in_array('rateLimitExceeded', $reasons, true)
                || in_array('userRateLimitExceeded', $reasons, true)
                || in_array('RATE_LIMIT_EXCEEDED', $reasons, true) => self::UNAVAILABLE,
            $status === 403 => self::PERMISSION_DENIED,
            $status === 404 => self::NOT_FOUND,
            $googleStatus === 'FAILED_PRECONDITION' => self::FAILED_PRECONDITION,
            default => self::BAD_REQUEST,
        };

        $summary = trim(sprintf('%s failed: HTTP %d %s', $what, $status, $googleStatus ?? ''));

        return new self($kind, $summary, $status, $message !== null ? mb_substr($message, 0, 200) : null);
    }
}
