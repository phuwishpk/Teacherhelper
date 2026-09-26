<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\ModelVersions\Pages\ManageModelVersions;
use App\Models\ModelVersion;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §7.5: admins upload model versions and choose the active one.
 */
class ModelVersionResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());
    }

    private function version(string $version, bool $active = false): ModelVersion
    {
        $path = ModelVersion::pathFor('digit_crnn', $version);
        Storage::disk('local')->put($path, "bytes-{$version}");

        return ModelVersion::create([
            'name' => 'digit_crnn',
            'version' => $version,
            'file_path' => $path,
            'sha256' => hash('sha256', "bytes-{$version}"),
            'metrics' => ['metrics' => ['cer' => 0.05, 'exact_match' => 0.8]],
            'is_active' => $active,
        ]);
    }

    public function test_versions_are_listed_and_activated(): void
    {
        $old = $this->version('0.1.0', true);
        $new = $this->version('0.2.0');

        $this->get('/admin/model-versions')->assertOk();

        Livewire::test(ManageModelVersions::class)
            ->assertCanSeeTableRecords([$old, $new])
            ->assertSee('5.0 %')
            ->assertActionHidden(TestAction::make('activate')->table($old))
            ->assertActionVisible(TestAction::make('activate')->table($new))
            ->callAction(TestAction::make('activate')->table($new))
            ->assertHasNoActionErrors()
            ->assertNotified();

        $this->assertTrue($new->refresh()->is_active);
        $this->assertFalse($old->refresh()->is_active);
    }

    public function test_deleting_a_version_removes_its_file(): void
    {
        $model = $this->version('0.1.0');

        Livewire::test(ManageModelVersions::class)
            ->callAction(TestAction::make('delete')->table($model))
            ->assertHasNoActionErrors();

        $this->assertSame(0, ModelVersion::query()->count());
        Storage::disk('local')->assertMissing('models/digit_crnn/0.1.0.tflite');
    }

    public function test_admin_uploads_a_version_with_its_metrics(): void
    {
        $this->version('0.1.0', true);

        Livewire::test(ManageModelVersions::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'digit_crnn',
                'version' => '0.2.0',
                'file_path' => UploadedFile::fake()->createWithContent('model.tflite', 'TFL3-new-bytes'),
                'metrics' => json_encode(['decode' => ['abstain_below' => 0.9], 'metrics' => ['cer' => 0.04]]),
                'is_active' => true,
            ])
            ->assertHasNoActionErrors();

        $model = ModelVersion::query()->where('version', '0.2.0')->sole();
        $this->assertSame('models/digit_crnn/0.2.0.tflite', $model->file_path);
        $this->assertSame(hash('sha256', 'TFL3-new-bytes'), $model->sha256);
        $this->assertSame(0.9, $model->metrics['decode']['abstain_below']);
        $this->assertTrue($model->is_active);
        $this->assertFalse(ModelVersion::query()->where('version', '0.1.0')->sole()->is_active);
        Storage::disk('local')->assertExists('models/digit_crnn/0.2.0.tflite');

        Livewire::test(ManageModelVersions::class)
            ->callAction(TestAction::make('create'), data: [
                'name' => 'digit_crnn', 'version' => '0.2.0', 'file_path' => UploadedFile::fake()->createWithContent('m.tflite', 'x'), 'metrics' => 'not json',
            ])
            ->assertHasActionErrors(['version', 'metrics']);
    }
}
