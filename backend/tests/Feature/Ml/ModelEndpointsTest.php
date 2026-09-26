<?php

namespace Tests\Feature\Ml;

use App\Models\ModelVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §9.8 GET /ml/models/active?name= and /ml/models/{id}/file, and the
 * eduvision:register-model command that fills model_versions from an
 * exported directory (metrics.json + model.tflite).
 */
class ModelEndpointsTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher();
        $this->dir = sys_get_temp_dir().'/eduvision-model-'.uniqid();
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    /** Writes model.tflite + metrics.json like ml/train/export does. */
    private function export(string $version, string $bytes, ?string $sha256 = null): string
    {
        file_put_contents($this->dir.'/model.tflite', $bytes);
        file_put_contents($this->dir.'/metrics.json', json_encode([
            'name' => 'digit_crnn',
            'version' => $version,
            'file' => 'model.tflite',
            'sha256' => $sha256 ?? hash('sha256', $bytes),
            'charset' => '0123456789.-/',
            'decode' => ['method' => 'ctc_greedy', 'confidence' => 'emitting_mean_max_prob', 'abstain_below' => 0.8],
            'metrics' => ['cer' => 0.0587, 'exact_match' => 0.818, 'abstain_rate' => 0.033],
        ], JSON_PRETTY_PRINT));

        return $this->dir;
    }

    public function test_no_active_model_is_404_and_the_name_is_validated(): void
    {
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertNotFound()->assertJsonPath('code', 'model_not_found');
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active')->assertNotFound()->assertJsonPath('code', 'model_not_found');
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active?name=../etc')->assertStatus(422)->assertJsonValidationErrors(['name']);
        $this->asGuest()->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertUnauthorized();
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/999/file')->assertNotFound()->assertJsonPath('code', 'model_not_found');
    }

    public function test_register_model_stores_the_file_and_the_app_downloads_it_with_its_hash(): void
    {
        $bytes = 'TFL3'.random_bytes(2048);
        $this->artisan('eduvision:register-model', ['dir' => $this->export('0.1.0', $bytes)])
            ->expectsOutputToContain('Registered digit_crnn 0.1.0')
            ->assertSuccessful();

        $model = ModelVersion::query()->sole();
        $this->assertSame(['digit_crnn', '0.1.0', 'models/digit_crnn/0.1.0.tflite', hash('sha256', $bytes), true], [$model->name, $model->version, $model->file_path, $model->sha256, $model->is_active]);
        $this->assertSame(0.8, $model->metrics['decode']['abstain_below']);
        Storage::disk('local')->assertExists('models/digit_crnn/0.1.0.tflite');

        $active = $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertOk();
        $active->assertJsonPath('data.id', $model->id)
            ->assertJsonPath('data.version', '0.1.0')
            ->assertJsonPath('data.sha256', hash('sha256', $bytes))
            ->assertJsonPath('data.size_bytes', strlen($bytes))
            ->assertJsonPath('data.download_url', "/api/v1/ml/models/{$model->id}/file")
            ->assertJsonPath('data.metrics.decode.method', 'ctc_greedy')
            ->assertJsonPath('data.metrics.metrics.cer', 0.0587);

        $file = $this->asUser($this->teacher)->get($active->json('data.download_url'))->assertOk();
        $this->assertSame(hash('sha256', $bytes), $file->headers->get('X-Checksum-Sha256'));
        $this->assertSame('application/octet-stream', $file->headers->get('Content-Type'));
        $this->assertStringContainsString('digit_crnn-0.1.0.tflite', (string) $file->headers->get('Content-Disposition'));
        $this->assertSame($bytes, $file->streamedContent());

        // Students fetch the model too (the scan screen may run on any signed-in phone).
        $student = $this->enrollStudent($this->makeClassroom($this->teacher))['student'];
        $this->asUser($student)->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertOk();

        // Re-running is idempotent; a second version registered with --no-activate does not switch.
        $this->artisan('eduvision:register-model', ['dir' => $this->dir])->assertSuccessful();
        $this->assertSame(1, ModelVersion::query()->count());
        $newBytes = 'TFL3'.random_bytes(1024);
        $this->artisan('eduvision:register-model', ['dir' => $this->export('0.2.0', $newBytes), '--no-activate' => true])->assertSuccessful();
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertJsonPath('data.version', '0.1.0');
        ModelVersion::query()->where('version', '0.2.0')->sole()->activate();
        $this->asUser($this->teacher)->getJson('/api/v1/ml/models/active?name=digit_crnn')->assertJsonPath('data.version', '0.2.0');
        $this->assertSame(1, ModelVersion::query()->where('is_active', true)->count());

        // A file that left the disk: 410.
        Storage::disk('local')->delete('models/digit_crnn/0.1.0.tflite');
        $this->asUser($this->teacher)->getJson("/api/v1/ml/models/{$model->id}/file")->assertStatus(410)->assertJsonPath('code', 'model_file_missing');
    }

    public function test_register_model_refuses_a_hash_mismatch_and_a_broken_directory(): void
    {
        $this->artisan('eduvision:register-model', ['dir' => $this->export('0.1.0', 'bytes', str_repeat('a', 64))])
            ->expectsOutputToContain('sha256 mismatch')
            ->assertFailed();
        $this->assertSame(0, ModelVersion::query()->count());
        Storage::disk('local')->assertMissing('models/digit_crnn/0.1.0.tflite');

        $this->artisan('eduvision:register-model', ['dir' => sys_get_temp_dir().'/does-not-exist'])->assertFailed();

        file_put_contents($this->dir.'/metrics.json', '{"name": "Bad Name", "version": "1"}');
        $this->artisan('eduvision:register-model', ['dir' => $this->dir])->assertFailed();
    }
}
