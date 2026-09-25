<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Fcm\AccessTokens;
use App\Domain\Notifications\Fcm\FcmClient;
use App\Domain\Notifications\Fcm\FirebaseCredentialsInvalid;
use App\Domain\Notifications\Fcm\ServiceAccount;
use App\Domain\Notifications\FcmNotifier;
use App\Domain\Notifications\LogNotifier;
use App\Domain\Notifications\Notifier;
use App\Models\Assignment;
use App\Models\DeviceToken;
use App\Models\Submission;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * FCM HTTP v1 with a service-account JWT (DESIGN §7.6, §9.9), against
 * Http::fake: token exchange, one send per device, dead-token cleanup, the
 * 401 retry, the cached access token and the binding by FIREBASE_CREDENTIALS.
 */
class FcmNotifierTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private const SEND_URL = 'https://fcm.googleapis.com/v1/projects/eduvision-test/messages:send';

    private string $publicKey;

    private string $credentialsPath;

    /** @var array<string, mixed> */
    private array $serviceAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $privatePem);
        $this->publicKey = openssl_pkey_get_details($key)['key'];
        $this->serviceAccount = [
            'type' => 'service_account',
            'project_id' => 'eduvision-test',
            'private_key_id' => 'kid-123',
            'private_key' => $privatePem,
            'client_email' => 'fcm-sender@eduvision-test.iam.gserviceaccount.com',
            'token_uri' => self::TOKEN_URI,
        ];
        $this->credentialsPath = tempnam(sys_get_temp_dir(), 'fcm').'.json';
        file_put_contents($this->credentialsPath, json_encode($this->serviceAccount));
    }

    protected function tearDown(): void
    {
        @unlink($this->credentialsPath);
        parent::tearDown();
    }

    private function useCredentials(?string $path): Notifier
    {
        config(['services.firebase.credentials' => $path]);
        $this->app->forgetInstance(Notifier::class);

        return $this->app->make(Notifier::class);
    }

    private function fcm(): FcmNotifier
    {
        $account = ServiceAccount::fromArray($this->serviceAccount);

        return new FcmNotifier(new FcmClient($account, new AccessTokens($account)));
    }

    /** A teacher with devices, and an assignment of theirs. */
    private function teacherWithDevices(string ...$tokens): array
    {
        $teacher = $this->makeTeacher();
        foreach ($tokens as $token) {
            DeviceToken::create(['user_id' => $teacher->id, 'fcm_token' => $token, 'last_seen_at' => now()]);
        }
        $assignment = Assignment::factory()->for_classroom($this->makeClassroom($teacher))->create(['title' => 'การบ้านบทที่ 3']);

        return [$teacher, $assignment->load('classroom')];
    }

    private function fakeGoogle(array $byDevice = [], int $tokenStatus = 200): void
    {
        Http::fake([
            self::TOKEN_URI => Http::response($tokenStatus === 200 ? ['access_token' => 'ya29.test-access', 'expires_in' => 3599, 'token_type' => 'Bearer'] : ['error' => 'invalid_grant'], $tokenStatus),
            self::SEND_URL => function (Request $request) use ($byDevice) {
                $device = $request['message']['token'];

                return match ($byDevice[$device] ?? 'ok') {
                    'unregistered' => Http::response(['error' => ['code' => 404, 'status' => 'NOT_FOUND', 'message' => 'Requested entity was not found.', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'UNREGISTERED']]]], 404),
                    'invalid' => Http::response(['error' => ['code' => 400, 'status' => 'INVALID_ARGUMENT', 'message' => 'The registration token is not a valid FCM registration token', 'details' => [['@type' => 'type.googleapis.com/google.firebase.fcm.v1.FcmError', 'errorCode' => 'INVALID_ARGUMENT']]]], 400),
                    'unavailable' => Http::response(['error' => ['code' => 503, 'status' => 'UNAVAILABLE']], 503),
                    default => Http::response(['name' => 'projects/eduvision-test/messages/0:1'], 200),
                };
            },
        ]);
    }

    public function test_the_binding_follows_firebase_credentials(): void
    {
        $this->assertInstanceOf(LogNotifier::class, $this->useCredentials(null));
        $this->assertInstanceOf(LogNotifier::class, $this->useCredentials(''));
        $this->assertInstanceOf(FcmNotifier::class, $this->useCredentials($this->credentialsPath));

        Log::spy();
        $this->assertInstanceOf(LogNotifier::class, $this->useCredentials('/nonexistent/firebase.json'));
        Log::shouldHaveReceived('error')->with('fcm.credentials_invalid', \Mockery::on(fn (array $c) => str_contains($c['message'], 'not found')))->once();
    }

    public function test_a_broken_key_file_is_named_without_its_contents(): void
    {
        foreach ([
            ['type' => 'authorized_user'],
            ['type' => 'service_account', 'client_email' => 'x@y', 'private_key' => 'not a key', 'project_id' => 'p'],
            ['type' => 'service_account', 'client_email' => 'x@y', 'private_key' => $this->serviceAccount['private_key']],
        ] as $json) {
            try {
                ServiceAccount::fromArray($json);
                $this->fail('accepted '.json_encode(array_keys($json)));
            } catch (FirebaseCredentialsInvalid $e) {
                $this->assertStringNotContainsString('PRIVATE KEY', $e->getMessage());
            }
        }
        $this->assertSame('override', ServiceAccount::fromArray($this->serviceAccount, 'override')->projectId);
    }

    public function test_sends_to_every_device_and_deletes_dead_tokens(): void
    {
        [$teacher, $assignment] = $this->teacherWithDevices('tok-good', 'tok-dead', 'tok-bad', 'tok-flaky');
        $this->fakeGoogle(['tok-dead' => 'unregistered', 'tok-bad' => 'invalid', 'tok-flaky' => 'unavailable']);

        $this->fcm()->gradingFinished($assignment, 12, 0);

        $this->assertEqualsCanonicalizing(['tok-good', 'tok-flaky'], DeviceToken::query()->pluck('fcm_token')->all());

        $sends = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->values();
        $this->assertCount(4, $sends);
        [$request] = $sends[0];
        $this->assertSame('Bearer ya29.test-access', $request->header('Authorization')[0]);
        $this->assertSame([
            'token' => 'tok-good',
            'notification' => ['title' => 'EduVision', 'body' => 'ตรวจ การบ้านบทที่ 3 เสร็จแล้ว มี 12 ข้อรอตรวจทาน'],
            'data' => ['type' => 'grading_done', 'assignment_id' => (string) $assignment->id],
            'android' => ['notification' => ['tag' => 'grading_done']],
        ], $request['message']);

        // The OAuth assertion is an RS256 JWT of the service account.
        $tokenRequests = Http::recorded(fn (Request $r) => $r->url() === self::TOKEN_URI)->values();
        $this->assertCount(1, $tokenRequests, 'one access token for all four sends');
        [$tokenRequest] = $tokenRequests[0];
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $tokenRequest['grant_type']);
        $claims = (array) JWT::decode($tokenRequest['assertion'], new Key($this->publicKey, 'RS256'));
        $this->assertSame($this->serviceAccount['client_email'], $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        $this->assertSame(self::TOKEN_URI, $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function test_the_access_token_is_cached_encrypted_and_renewed_after_a_401(): void
    {
        [$teacher, $assignment] = $this->teacherWithDevices('tok-1');
        $issued = 0;
        $rejectNext = false;
        Http::fake([
            self::TOKEN_URI => function () use (&$issued) {
                $issued++;

                return Http::response(['access_token' => "ya29.access-{$issued}", 'expires_in' => 3599]);
            },
            self::SEND_URL => function () use (&$rejectNext) {
                if ($rejectNext) {
                    $rejectNext = false;

                    return Http::response(['error' => ['code' => 401, 'status' => 'UNAUTHENTICATED']], 401);
                }

                return Http::response(['name' => 'projects/eduvision-test/messages/0:1']);
            },
        ]);
        $notifier = $this->fcm();

        $notifier->gradingFinished($assignment, 1, 0);
        $notifier->gradingFinished($assignment, 2, 0);
        $this->assertSame(1, $issued, 'the second push reuses the cached token');
        $cacheKey = 'fcm:access-token:'.sha1($this->serviceAccount['client_email'].'|eduvision-test');
        $stored = Cache::get($cacheKey);
        $this->assertIsString($stored);
        $this->assertStringNotContainsString('ya29', $stored, 'stored encrypted');

        // Google expired the cached token early: 401, a new token, one retry.
        $rejectNext = true;
        $notifier->gradingFinished($assignment, 3, 0);

        $this->assertSame(2, $issued);
        $sends = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->values();
        $this->assertCount(4, $sends);
        $this->assertSame('Bearer ya29.access-1', $sends[2][0]->header('Authorization')[0]);
        $this->assertSame('Bearer ya29.access-2', $sends[3][0]->header('Authorization')[0]);
        $this->assertSame(1, DeviceToken::query()->count(), 'a 401 says nothing about the device');
    }

    public function test_google_refusing_the_service_account_is_logged_not_thrown(): void
    {
        [$teacher, $assignment] = $this->teacherWithDevices('tok-1');
        $this->fakeGoogle(tokenStatus: 400);
        Log::spy();

        $this->fcm()->gradingFinished($assignment, 1, 0);

        Log::shouldHaveReceived('warning')->with('fcm.send_failed', \Mockery::on(fn (array $c) => str_contains($c['error'], 'invalid_grant') && ! str_contains(json_encode($c), 'tok-1')))->once();
        $this->assertSame(1, DeviceToken::query()->count());
        $this->assertCount(0, Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL));
    }

    public function test_students_get_the_result_notice_without_a_score(): void
    {
        [$teacher, $assignment] = $this->teacherWithDevices();
        $student = User::factory()->student($teacher->school)->create();
        DeviceToken::create(['user_id' => $student->id, 'fcm_token' => 'tok-student', 'last_seen_at' => now()]);
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'status' => 'published', 'total_score' => 7.5, 'published_at' => now()]);
        $this->fakeGoogle();

        $this->fcm()->resultPublished($submission->load('assignment'));

        [$request] = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->values()[0];
        $this->assertSame('ผลการบ้าน การบ้านบทที่ 3 ออกแล้ว', $request['message']['notification']['body']);
        $this->assertStringNotContainsString('7.5', json_encode($request['message'], JSON_UNESCAPED_UNICODE));
        $this->assertSame(['type' => 'results_published', 'submission_id' => (string) $submission->id, 'assignment_id' => (string) $assignment->id], $request['message']['data']);
    }

    public function test_the_log_notifier_carries_no_tokens(): void
    {
        [$teacher, $assignment] = $this->teacherWithDevices('tok-secret');
        Log::spy();

        (new LogNotifier)->gradingFinished($assignment, 4, 1);

        Log::shouldHaveReceived('info')->with('notify.grading_done', \Mockery::on(fn (array $c) => $c['user_ids'] === [$teacher->id]
            && $c['text'] === 'ใส่ Gemini API key ก่อน: การบ้านบทที่ 3 มี 1 ข้อที่ AI ยังไม่ได้ตรวจ และ 3 ข้อรอตรวจทาน'
            && ! str_contains(json_encode($c), 'tok-secret')))->once();
    }

    public function test_the_check_command_proves_the_setup_and_sends_a_test_push(): void
    {
        [$teacher] = $this->teacherWithDevices('tok-check');
        $this->fakeGoogle();

        config(['services.firebase.credentials' => null]);
        $this->artisan('eduvision:fcm-check')->assertFailed();

        config(['services.firebase.credentials' => $this->credentialsPath]);
        $this->artisan('eduvision:fcm-check')
            ->expectsOutputToContain('project: eduvision-test')
            ->expectsOutputToContain('access token ok')
            ->assertSuccessful();
        $this->artisan('eduvision:fcm-check', ['--user' => $teacher->id])
            ->expectsOutputToContain('sent')
            ->doesntExpectOutputToContain('tok-check')
            ->assertSuccessful();
        [$request] = Http::recorded(fn (Request $r) => $r->url() === self::SEND_URL)->values()[0];
        $this->assertSame('ทดสอบการแจ้งเตือนจาก EduVision', $request['message']['notification']['body']);
    }
}
