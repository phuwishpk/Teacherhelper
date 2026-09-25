<?php

namespace App\Providers;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\HttpGeminiClient;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Notifications\Fcm\AccessTokens;
use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Domain\Notifications\Fcm\ServiceAccount;
use App\Domain\Notifications\FcmNotifier;
use App\Domain\Notifications\LogNotifier;
use App\Domain\Notifications\Notifier;
use App\Domain\Worksheets\QrSigner;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Gemini transport (DESIGN §10): the real REST client, or the offline
        // deterministic fake when GEMINI_FAKE=true (tests, local demo). The
        // fake scores real students from an image hash and accepts any key, so
        // production never gets it: the flag is ignored there with an error log.
        $this->app->singleton(GeminiClient::class, function ($app) {
            if (config('services.gemini.fake')) {
                if (! $app->environment('production')) {
                    return new FakeGeminiClient((string) config('services.gemini.model'));
                }
                Log::error('gemini.fake_refused', ['message' => 'GEMINI_FAKE=true is ignored in production; using the real Gemini API. Set GEMINI_FAKE=false in .env.']);
            }

            return HttpGeminiClient::fromConfig();
        });
        $this->app->singleton(PromptRepository::class);

        // Push notifications (DESIGN §9.9): FCM HTTP v1 when FIREBASE_CREDENTIALS
        // points at a usable service-account key, otherwise only logged. A
        // broken key file logs an error (never its contents) and falls back to
        // the log, so grading and publishing never fail over pushes.
        $this->app->singleton(Notifier::class, function () {
            $path = trim((string) config('services.firebase.credentials'));
            if ($path === '') {
                return new LogNotifier;
            }
            try {
                $account = ServiceAccount::fromFile($path, config('services.firebase.project_id') ?: null);
            } catch (FirebaseCredentialsInvalid $e) {
                Log::error('fcm.credentials_invalid', ['message' => $e->getMessage()]);

                return new LogNotifier;
            }
            $timeout = (int) config('services.firebase.timeout', 10);

            return new FcmNotifier(new FcmClient($account, new AccessTokens($account, $timeout), $timeout));
        });

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
