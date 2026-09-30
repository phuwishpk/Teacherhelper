<?php

namespace Tests\Feature\Security;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\HttpGeminiClient;
use App\Domain\Notifications\Notifier;
use App\Jobs\GradeScanJob;
use App\Models\AiCall;
use App\Models\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * DESIGN §16.1 (b) and §1 principle 3: what leaves the server for Gemini is
 * the answer crop(s) and the teacher's question, key or rubric. The fake
 * records every request; each one is rendered to the exact JSON body
 * HttpGeminiClient would POST and searched for anything that identifies the
 * student, the class or the school, for the page image and for any key.
 */
class GeminiPayloadPrivacyTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private FakeGeminiClient $gemini;

    /** @var list<string> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->open->rubricCriteria()->createMany([
            ['position' => 1, 'description' => 'บอกได้ว่าคลอโรฟิลล์สะท้อนแสงสีเขียว', 'points' => 2, 'is_core' => true, 'source' => 'teacher'],
            ['position' => 2, 'description' => 'อธิบายการดูดกลืนแสงสีอื่น', 'points' => 1, 'is_core' => false, 'source' => 'teacher'],
            ['position' => 3, 'description' => 'ใช้คำศัพท์ถูกต้อง', 'points' => 1, 'is_core' => false, 'source' => 'teacher'],
        ]);
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->app->instance(Notifier::class, new RecordingNotifier);
        Log::listen(function ($event) {
            $this->logged[] = $event->message.' '.json_encode($event->context, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
    }

    /** @return list<string> strings that must never reach Google */
    private function secrets(): array
    {
        return [
            $this->student->name,
            'ด.ญ.',
            $this->classroom->name,
            $this->classroom->class_code,
            $this->teacher->name,
            $this->teacher->email,
            $this->assignment->school->name,
            $this->qr(1),
            $this->qr(2),
            'EV1.',
            'student_id',
            'student_number',
            'testing-server-gemini-key-not-real',
            base64_encode($this->fixtureBytes('page.webp')),
        ];
    }

    private function grade(int $page): void
    {
        $scanId = (int) $this->postScan($this->metaFor($page))->assertStatus(201)->json('scan_id');
        $this->app->call([(new GradeScanJob($scanId))->withFakeQueueInteractions(), 'handle']);
    }

    public function test_every_wire_payload_of_grading_holds_only_answer_crops_and_the_teachers_material(): void
    {
        $this->short->update(['prompt_text' => $this->short->prompt_text.' [fake:partial]']);
        $this->work->update(['prompt_text' => $this->work->prompt_text.' [fake:partial]']);
        $this->open->update(['prompt_text' => $this->open->prompt_text.' [fake:wrong]']);
        $this->grade(1);
        $this->grade(2);

        // The teacher asks for a fresh explanation: one more text-only request.
        $work = Response::query()->where('question_id', $this->work->id)->sole();
        $this->asUser($this->teacher)->postJson("/api/v1/responses/{$work->id}/regenerate-explanation")->assertOk();

        $client = HttpGeminiClient::fromConfig();
        $crops = [base64_encode($this->fixtureBytes('crop.webp')), base64_encode($this->fixtureBytes('crop_alt.webp'))];
        $purposes = [];

        $this->assertNotEmpty($this->gemini->requests);
        foreach ($this->gemini->requests as $request) {
            $purposes[] = $request->purpose;
            $payload = $client->payload($request);
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $this->assertIsString($json);

            foreach ($this->secrets() as $secret) {
                $this->assertStringNotContainsString($secret, $json, "{$request->purpose} payload carries '{$secret}'");
            }
            $this->assertArrayNotHasKey('hints', $payload, 'hints are for the fake only');

            $parts = $payload['contents'][0]['parts'];
            $images = array_values(array_filter($parts, fn ($p) => isset($p['inlineData'])));
            foreach ($images as $image) {
                $this->assertSame('image/webp', $image['inlineData']['mimeType']);
                $this->assertContains($image['inlineData']['data'], $crops, "{$request->purpose} sends an answer crop, never the page");
            }
            if ($request->purpose === 'explanation') {
                $this->assertSame([], $images, 'explanations are text only (§10.5)');
            } else {
                $this->assertNotEmpty($images, 'extraction sends the crop');
            }
        }

        $this->assertEqualsCanonicalizing(['extract', 'extract_batch', 'explanation'], array_unique($purposes));
        $this->assertGreaterThanOrEqual(1, count(array_filter($purposes, fn ($p) => $p === 'extract')), 'the page with one answer (short) goes alone');
        $this->assertGreaterThanOrEqual(1, count(array_filter($purposes, fn ($p) => $p === 'extract_batch')), 'show_work and open of one page share a call (§21.4)');
    }

    public function test_ai_calls_and_the_log_hold_neither_student_data_nor_images_nor_keys(): void
    {
        $this->work->update(['prompt_text' => $this->work->prompt_text.' [fake:partial] [fake:explanation-error]']);
        $this->open->update(['prompt_text' => $this->open->prompt_text.' [fake:invalid-once]']);
        $this->grade(2);

        $this->assertGreaterThan(0, AiCall::query()->count());
        $imageBytes = [$this->fixtureBytes('crop.webp'), $this->fixtureBytes('crop_alt.webp'), $this->fixtureBytes('page.webp')];
        foreach (AiCall::query()->get() as $call) {
            $row = json_encode($call->getAttributes(), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
            foreach ($this->secrets() as $secret) {
                $this->assertStringNotContainsString($secret, (string) $row, "ai_calls #{$call->id} carries '{$secret}'");
            }
            foreach ($imageBytes as $bytes) {
                $this->assertStringNotContainsString(base64_encode($bytes), (string) $row);
            }
            $this->assertArrayNotHasKey('prompt', $call->getAttributes());
        }

        $log = implode("\n", $this->logged);
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $log, "the log carries '{$secret}'");
        }
    }
}
