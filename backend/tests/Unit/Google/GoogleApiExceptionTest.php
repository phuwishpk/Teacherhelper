<?php

namespace Tests\Unit\Google;

use App\Domain\Google\GoogleApiException;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Http\Client\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GoogleApiExceptionTest extends TestCase
{
    /**
     * @return array<string, array{0: int, 1: array<string, mixed>, 2: string}>
     */
    public static function errors(): array
    {
        $error = fn (int $code, string $status, string $message, array $extra = []) => ['error' => ['code' => $code, 'status' => $status, 'message' => $message, ...$extra]];

        return [
            'ProjectPermissionDenied' => [403, $error(403, 'PERMISSION_DENIED', '@ProjectPermissionDenied The Developer Console project is not permitted to make this request.'), GoogleApiException::PROJECT_PERMISSION_DENIED],
            'insufficient scopes (ErrorInfo)' => [403, $error(403, 'PERMISSION_DENIED', 'Request had insufficient authentication scopes.', ['details' => [['reason' => 'ACCESS_TOKEN_SCOPE_INSUFFICIENT']]]), GoogleApiException::SCOPE_MISSING],
            'insufficient scopes (Drive v3 errors[])' => [403, $error(403, 'PERMISSION_DENIED', 'Insufficient Permission', ['errors' => [['reason' => 'insufficientPermissions']]]), GoogleApiException::SCOPE_MISSING],
            'API disabled' => [403, $error(403, 'PERMISSION_DENIED', 'Google Classroom API has not been used in project 1 before or it is disabled.', ['details' => [['reason' => 'SERVICE_DISABLED']]]), GoogleApiException::API_DISABLED],
            'Classroom off for the domain' => [403, $error(403, 'PERMISSION_DENIED', '@ClassroomApiDisabled The user is not permitted to access the Classroom API.'), GoogleApiException::API_DISABLED],
            'other 403' => [403, $error(403, 'PERMISSION_DENIED', 'The caller does not have permission'), GoogleApiException::PERMISSION_DENIED],
            'not found' => [404, $error(404, 'NOT_FOUND', 'Requested entity was not found.'), GoogleApiException::NOT_FOUND],
            'failed precondition' => [400, $error(400, 'FAILED_PRECONDITION', 'Precondition check failed.'), GoogleApiException::FAILED_PRECONDITION],
            'bad request' => [400, $error(400, 'INVALID_ARGUMENT', 'Invalid dueDate'), GoogleApiException::BAD_REQUEST],
            'rate limited' => [429, $error(429, 'RESOURCE_EXHAUSTED', 'Quota exceeded'), GoogleApiException::UNAVAILABLE],
            'server error' => [503, ['error' => 'oops'], GoogleApiException::UNAVAILABLE],
            'user rate limit 403' => [403, $error(403, 'PERMISSION_DENIED', 'User rate limit exceeded', ['errors' => [['reason' => 'userRateLimitExceeded']]]), GoogleApiException::UNAVAILABLE],
            'unauthenticated' => [401, $error(401, 'UNAUTHENTICATED', 'Request had invalid authentication credentials.'), GoogleApiException::INVALID_GRANT],
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    #[DataProvider('errors')]
    public function test_google_errors_are_typed(int $status, array $body, string $kind): void
    {
        $e = GoogleApiException::fromApiResponse(new Response(new Psr7Response($status, ['Content-Type' => 'application/json'], json_encode($body))), 'test.call');

        $this->assertSame($kind, $e->kind);
        $this->assertSame($status, $e->httpStatus);
        $this->assertStringStartsWith("test.call failed: HTTP {$status}", $e->getMessage());
        $this->assertSame($kind === GoogleApiException::UNAVAILABLE, $e->isTransient());
    }
}
