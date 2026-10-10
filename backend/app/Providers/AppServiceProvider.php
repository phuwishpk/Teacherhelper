<?php

namespace App\Providers;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiBatchClient;
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
use App\Domain\Students\StudentUsernames;
use App\Domain\Worksheets\QrSigner;
use App\Models\ClassroomCourseRequest;
use App\Policies\CourseRequestPolicy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
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
        // The Batch API (DESIGN §20.8) goes through the same transport, so the
        // fake answers batches too. Resolved on every use: a test that swaps
        // GeminiClient swaps the batches with it.
        $this->app->bind(GeminiBatchClient::class, function ($app) {
            $client = $app->make(GeminiClient::class);

            return $client instanceof GeminiBatchClient ? $client : HttpGeminiClient::fromConfig();
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
        // DESIGN §24.8 names it CourseRequestPolicy (auto-discovery would look for ClassroomCourseRequestPolicy).
        Gate::policy(ClassroomCourseRequest::class, CourseRequestPolicy::class);

        // Rate limits (DESIGN §7.4). Every limiter is named: Laravel prefixes a
        // named limiter's key with its name, so each one below is its own
        // bucket. (A bare `throttle:N,M` keys on the user id alone, so every
        // route using it would share one counter per user.)

        // General API limit per user (or per IP before login), applied to
        // every /api/v1 route by throttleApi() in bootstrap/app.php.
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(120)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
        });

        // Teacher register + login: one bucket per address on purpose, so
        // guessing passwords and mass sign-ups draw from the same 10.
        RateLimiter::for('teacher-auth', function (Request $request) {
            return Limit::perMinute(10)->by((string) $request->ip());
        });

        // GET /auth/schools (the sign-up form's school list): public and cheap,
        // its own per-IP bucket so opening the form never spends a login try.
        RateLimiter::for('school-list', fn (Request $request) => Limit::perMinute(30)->by((string) $request->ip()));

        // Student login (DESIGN §7.4): a whole class scans its QR cards from one
        // school NAT address within a minute, so the per-IP limit is wide. QR
        // tokens are 256-bit random, so the IP limit is only abuse protection;
        // the password path adds a per-credential limit on top of the 5-attempt
        // lockout in StudentAuthenticator.
        RateLimiter::for('student-auth', function (Request $request) {
            $limits = [Limit::perMinute(120)->by('ip|'.$request->ip())];

            if ($request->routeIs('api.auth.student.login', 'api.auth.google.link-with-password')) {
                $limits[] = Limit::perMinute(10)->by(implode('|', [
                    'password',
                    $request->ip(),
                    StudentUsernames::normalize(self::scalarInput($request, 'username')),
                ]));
            }

            return $limits;
        });

        // The admin's one-time link to the Filament panel (§7.4): the app asks
        // once per tap, so 10 a minute per admin is plenty; the link itself is
        // capped per address (it has no login, a bad token only redirects).
        RateLimiter::for('admin-handoff', fn (Request $request) => self::perUser($request, 10));
        RateLimiter::for('admin-handoff-link', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));

        // Endpoints that call Google on the teacher's behalf (DESIGN §18.6): a
        // runaway client must not burn the Cloud project's Classroom quota.
        RateLimiter::for('google', fn (Request $request) => self::perUser($request, 30));
        // GET /google/oauth/callback has no login; a bad state is refused
        // before anything reaches Google, so this only caps probing.
        RateLimiter::for('google-oauth-callback', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        // Google sign-in (DESIGN §24.9.5): ID tokens are signed, so this only caps
        // probing and the work of verifying; the browser flow's callback has no login.
        RateLimiter::for('google-signin', fn (Request $request) => Limit::perMinute(10)->by((string) $request->ip()));
        RateLimiter::for('google-signin-callback', fn (Request $request) => Limit::perMinute(20)->by((string) $request->ip()));

        // Endpoints that reach Gemini directly or queue a Gemini job (they
        // cost the teacher's or the school's quota), and the student write
        // endpoints. Each has its own bucket per user.
        RateLimiter::for('ai-key', fn (Request $request) => self::perUser($request, 10));
        RateLimiter::for('explanation', fn (Request $request) => self::perUser($request, 20));
        RateLimiter::for('practice-generate', fn (Request $request) => self::perUser($request, 10));
        // Answer keys read or drafted from documents (§19.5) and the uploads they read.
        RateLimiter::for('answer-key', fn (Request $request) => self::perUser($request, 10));
        // Reading a course document or lesson plans (§20.1): one Gemini call each unless cached.
        RateLimiter::for('course-extract', fn (Request $request) => self::perUser($request, 10));
        // Indicator suggestions of an assignment (§20.3): one Gemini job per request.
        RateLimiter::for('indicator-suggest', fn (Request $request) => self::perUser($request, 10));
        // "วิเคราะห์ตอนนี้" calls Gemini synchronously in the request (§20.5).
        RateLimiter::for('analysis-now', fn (Request $request) => self::perUser($request, 10));
        // "ตรวจใหม่ทั้งห้อง" (§21.13): one request may queue a Gemini read of every hand-in.
        RateLimiter::for('regrade', fn (Request $request) => self::perUser($request, 5));
        RateLimiter::for('documents', fn (Request $request) => self::perUser($request, 20));
        RateLimiter::for('appeal', fn (Request $request) => self::perUser($request, 30));
        RateLimiter::for('practice-attempt', fn (Request $request) => self::perUser($request, 60));
        // Whole-page hand-ins (§19.6): each stores up to 5 pages and may queue Gemini reads.
        // A student hands in a few times at most; a teacher uploads a class one student at a time.
        RateLimiter::for('student-submission', fn (Request $request) => self::perUser($request, 10));
        RateLimiter::for('page-upload', fn (Request $request) => self::perUser($request, 60));
        // Exam question and option images (§22.15): GD scales each one in the request.
        RateLimiter::for('exam-images', fn (Request $request) => self::perUser($request, 60));
        // Reading an exam file (§22.4): one Gemini call unless cached, and a
        // cached read writes up to 200 draft questions in the request.
        RateLimiter::for('exam-import', fn (Request $request) => self::perUser($request, 10));
    }

    /** A per-user limit (per address before login; these routes all need a token). */
    private static function perUser(Request $request, int $perMinute): Limit
    {
        return Limit::perMinute($perMinute)->by((string) ($request->user()?->getAuthIdentifier() ?: $request->ip()));
    }

    /** The limiter runs before validation, so the input may be anything. */
    private static function scalarInput(Request $request, string $key): string
    {
        $value = $request->input($key);

        return is_scalar($value) ? (string) $value : '';
    }
}
