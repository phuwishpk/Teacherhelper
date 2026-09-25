<?php

namespace Tests\Feature\Api;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Models\TeacherApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * DESIGN §9.1 GET / PUT / DELETE /me/ai-key and §10.1 GeminiKeyResolver:
 * the teacher's own Gemini key is verified, stored encrypted, shown as its
 * last 4 characters and never returned.
 */
class AiKeyTest extends TestCase
{
    use RefreshDatabase;

    private const KEY = 'TESTSyTeacherOwnKeyForTesting000000wxyz';

    public function test_status_without_a_key(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->getJson('/api/v1/me/ai-key')
            ->assertOk()
            ->assertExactJson(['data' => [
                'provider' => 'gemini',
                'configured' => false,
                'key_last4' => null,
                'last_verified_at' => null,
                'server_key_available' => true, // phpunit.xml sets a dummy GEMINI_API_KEY
            ]]);

        config(['services.gemini.api_key' => '  ']);
        $this->asUser($teacher)->getJson('/api/v1/me/ai-key')->assertJsonPath('data.server_key_available', false);
    }

    public function test_a_verified_key_is_stored_encrypted_and_never_returned(): void
    {
        $this->freezeSecond();
        $teacher = $this->makeTeacher();
        $fake = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $fake);

        $res = $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => '  '.self::KEY.' '])
            ->assertOk()
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.key_last4', 'wxyz')
            ->assertJsonPath('data.last_verified_at', now()->utc()->toIso8601String());
        $this->assertStringNotContainsString(self::KEY, $res->getContent());

        $raw = DB::table('teacher_api_keys')->where('user_id', $teacher->id)->first();
        $this->assertNotNull($raw);
        $this->assertStringNotContainsString(self::KEY, $raw->encrypted_key, 'encrypted with APP_KEY');
        $this->assertSame(self::KEY, TeacherApiKey::query()->findOrFail($teacher->id)->encrypted_key);
        $this->assertArrayNotHasKey('encrypted_key', TeacherApiKey::query()->findOrFail($teacher->id)->toArray());

        $this->asUser($teacher)->getJson('/api/v1/me/ai-key')
            ->assertJsonPath('data.configured', true)
            ->assertJsonPath('data.key_last4', 'wxyz');

        // Replacing the key keeps one row.
        $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => 'TESTSyAnotherTeacherKey00000000000001111'])
            ->assertJsonPath('data.key_last4', '1111');
        $this->assertSame(1, TeacherApiKey::query()->count());
    }

    public function test_a_key_google_rejects_is_not_stored(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => 'TESTSyThisKeyIsInvalid0000000000000000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'ai_key_invalid')
            ->assertJsonStructure(['message', 'errors' => ['gemini_api_key'], 'code']);

        $this->assertSame(0, TeacherApiKey::query()->count());
    }

    public function test_an_unreachable_gemini_is_a_503_and_nothing_is_stored(): void
    {
        $teacher = $this->makeTeacher();

        $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => 'TESTSyGeminiUnavailable000000000000000'])
            ->assertStatus(503)
            ->assertJsonPath('code', 'ai_unavailable');

        $this->assertSame(0, TeacherApiKey::query()->count());
    }

    public function test_malformed_keys_fail_validation_without_calling_gemini(): void
    {
        $teacher = $this->makeTeacher();
        $fake = new FakeGeminiClient;
        $this->app->instance(GeminiClient::class, $fake);

        foreach (['', 'short', 'TEST has spaces 000000000000000000', str_repeat('a', 201), ['array']] as $bad) {
            $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => $bad])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['gemini_api_key']);
        }
        $this->assertSame(0, TeacherApiKey::query()->count());
    }

    public function test_the_key_can_be_deleted(): void
    {
        $teacher = $this->makeTeacher();
        $this->asUser($teacher)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => self::KEY])->assertOk();

        $this->asUser($teacher)->deleteJson('/api/v1/me/ai-key')
            ->assertOk()
            ->assertJsonPath('data.configured', false)
            ->assertJsonPath('data.key_last4', null);
        $this->assertSame(0, TeacherApiKey::query()->count());

        $this->asUser($teacher)->deleteJson('/api/v1/me/ai-key')->assertOk(); // idempotent
    }

    public function test_only_active_teachers_manage_a_key(): void
    {
        $school = $this->makeSchool();
        $student = User::factory()->student($school)->create();
        $this->asUser($student)->getJson('/api/v1/me/ai-key')->assertStatus(403);
        $this->asUser($student)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => self::KEY])->assertStatus(403);

        $admin = $this->makeAdmin();
        $this->asUser($admin, ['teacher'])->getJson('/api/v1/me/ai-key')->assertStatus(403);

        $this->asGuest()->getJson('/api/v1/me/ai-key')->assertStatus(401);
    }

    public function test_each_teacher_sees_only_their_own_key(): void
    {
        $a = $this->makeTeacher();
        $b = $this->makeTeacher($a->school);
        $this->asUser($a)->putJson('/api/v1/me/ai-key', ['gemini_api_key' => self::KEY])->assertOk();

        $this->asUser($b)->getJson('/api/v1/me/ai-key')->assertJsonPath('data.configured', false);
    }

    public function test_the_resolver_prefers_the_teacher_then_the_server(): void
    {
        $resolver = app(GeminiKeyResolver::class);
        $teacher = $this->makeTeacher();

        $server = $resolver->forTeacher($teacher->id);
        $this->assertSame([GeminiKey::SOURCE_SERVER, 'testing-server-gemini-key-not-real'], [$server->source, $server->apiKey]);

        TeacherApiKey::create(['user_id' => $teacher->id, 'provider' => 'gemini', 'encrypted_key' => self::KEY, 'key_last4' => 'wxyz']);
        $own = $resolver->forTeacher($teacher->id);
        $this->assertSame([GeminiKey::SOURCE_TEACHER, self::KEY], [$own->source, $own->apiKey]);
        $this->assertStringNotContainsString(self::KEY, print_r($own, true), 'debug output hides the key');

        config(['services.gemini.api_key' => null]);
        $this->assertNull($resolver->forTeacher($this->makeTeacher()->id));
        $this->assertNull($resolver->forTeacher(null));
    }

    public function test_a_key_that_no_longer_decrypts_falls_back_to_the_server_key(): void
    {
        Log::spy();
        $teacher = $this->makeTeacher();
        DB::table('teacher_api_keys')->insert([
            'user_id' => $teacher->id, 'provider' => 'gemini', 'encrypted_key' => 'not-an-encrypted-payload',
            'key_last4' => 'abcd', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $key = app(GeminiKeyResolver::class)->forTeacher($teacher->id);

        $this->assertSame(GeminiKey::SOURCE_SERVER, $key->source);
        Log::shouldHaveReceived('warning')->with('gemini.teacher_key_undecryptable', ['user_id' => $teacher->id]);
    }

    public function test_a_key_is_never_serialised(): void
    {
        $this->expectException(\LogicException::class);
        serialize(new GeminiKey(self::KEY, GeminiKey::SOURCE_TEACHER));
    }
}
