<?php

namespace Tests\Feature\Firestore;

use App\Domain\Firestore\FirebaseCustomTokens;
use App\Domain\Firestore\FirestoreUid;
use App\Models\User;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * POST /auth/firebase-token (DESIGN §38.4): 503 when Firestore is off, 401
 * for guests, a one-hour RS256 custom token for teachers and students whose
 * uid is u{id} and whose claims hold the role and nothing else.
 */
class FirebaseTokenTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->teacher = $this->makeTeacher();
        $this->enableFirestore(true);
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function decode(string $token): array
    {
        return (array) JWT::decode($token, new Key($this->publicKey, 'RS256'));
    }

    public function test_off_answers_503_and_guests_401(): void
    {
        $this->enableFirestore(false);
        $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')
            ->assertStatus(503)->assertJsonPath('code', 'firestore_not_configured');
        $this->asGuest()->postJson('/api/v1/auth/firebase-token')->assertUnauthorized();
    }

    public function test_a_teacher_gets_a_custom_token_for_their_uid(): void
    {
        $before = time();
        $response = $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')->assertOk();

        $uid = FirestoreUid::of($this->teacher);
        $this->assertSame('u'.$this->teacher->id, $uid);
        $this->assertSame($uid, $response->json('data.uid'));
        $claims = $this->decode($response->json('data.token'));
        $this->assertSame('live@eduvision-test.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame($claims['iss'], $claims['sub']);
        $this->assertSame(FirebaseCustomTokens::AUDIENCE, $claims['aud']);
        $this->assertSame($uid, $claims['uid']);
        $this->assertSame(['role' => 'teacher'], (array) $claims['claims']);
        $this->assertGreaterThanOrEqual($before, $claims['iat']);
        $this->assertSame($claims['iat'] + 3600, $claims['exp']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $claims['exp']), $response->json('data.expires_at'));
        // Nothing but the three fields: no key material, no email.
        $this->assertSame(['token', 'uid', 'expires_at'], array_keys($response->json('data')));
    }

    public function test_a_student_gets_their_own_uid_and_an_admin_token_is_refused(): void
    {
        $classroom = $this->makeClassroom($this->teacher);
        ['student' => $student] = $this->enrollStudent($classroom);

        $claims = $this->decode($this->asUser($student)->postJson('/api/v1/auth/firebase-token')->assertOk()->json('data.token'));
        $this->assertSame('u'.$student->id, $claims['uid']);
        $this->assertSame(['role' => 'student'], (array) $claims['claims']);

        $this->asUser($this->makeAdmin())->postJson('/api/v1/auth/firebase-token')->assertForbidden();
    }

    public function test_the_signature_is_the_service_accounts(): void
    {
        $token = $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')->json('data.token');
        $other = openssl_pkey_get_details(openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]))['key'];
        $this->expectException(SignatureInvalidException::class);
        JWT::decode($token, new Key($other, 'RS256'));
    }

    public function test_a_broken_key_file_is_503_without_leaking_it(): void
    {
        file_put_contents($this->credentialsPath, '{"type":"service_account","client_email":"x@y","private_key":"not-a-pem","project_id":"p"}');
        Log::shouldReceive('error')->once()->withArgs(fn (string $m, array $c) => $m === 'firestore.credentials_invalid' && ! str_contains(json_encode($c), 'not-a-pem'));

        $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')
            ->assertStatus(503)->assertJsonPath('code', 'firestore_unavailable')
            ->assertJsonMissing(['not-a-pem']);
    }

    public function test_the_endpoint_is_throttled_per_user(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')->assertOk();
        }
        $this->asUser($this->teacher)->postJson('/api/v1/auth/firebase-token')->assertStatus(429);
    }
}
