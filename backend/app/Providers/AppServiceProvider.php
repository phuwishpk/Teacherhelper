<?php

namespace App\Providers;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Worksheets\QrSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Canned, offline Gemini by default (DESIGN §10): development and tests
        // need no API key. The real HTTP client replaces this binding.
        $this->app->bind(GeminiClient::class, FakeGeminiClient::class);

        // Resolved lazily: a missing QR_SIGNING_KEY only fails the code paths
        // that sign or verify worksheet QRs, with a clear message.
        $this->app->bind(QrSigner::class, fn () => QrSigner::fromConfig());
    }

    public function boot(): void
    {
        // General API limit per user (or per IP before login). Teacher auth
        // endpoints use the stricter throttle:10,1 in routes/api.php (DESIGN §7.4).
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        // Student login (DESIGN §7.4): a whole class scans its QR cards from one
        // school NAT address within a minute, so the per-IP limit is wide. QR
        // tokens are 256-bit random, so the IP limit is only abuse protection;
        // the PIN path adds a per-credential limit on top of the 5-attempt
        // lockout in StudentAuthenticator.
        RateLimiter::for('student-auth', function (Request $request) {
            $limits = [Limit::perMinute(120)->by('ip|'.$request->ip())];

            if ($request->routeIs('api.auth.student.pin')) {
                $limits[] = Limit::perMinute(10)->by(implode('|', [
                    'pin',
                    $request->ip(),
                    ClassCodeGenerator::normalize(self::scalarInput($request, 'class_code')),
                    self::scalarInput($request, 'student_number'),
                ]));
            }

            return $limits;
        });
    }

    /** The limiter runs before validation, so the input may be anything. */
    private static function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
