<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\AiCalls\Pages\ManageAiCalls;
use App\Models\AiCall;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * DESIGN §7.5: the admin reads AI cost and errors from ai_calls.
 */
class AiCallResourceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel('admin');
        $this->actingAs($this->makeAdmin());
    }

    public function test_calls_are_listed_with_status_and_tokens(): void
    {
        $ok = AiCall::create(['purpose' => 'extract', 'model' => 'gemini-test', 'prompt_version' => 'v1', 'key_source' => 'teacher', 'input_tokens' => 1200, 'output_tokens' => 80, 'latency_ms' => 900, 'status' => 'ok']);
        $failed = AiCall::create(['purpose' => 'practice_gen', 'model' => 'gemini-test', 'prompt_version' => 'v1', 'key_source' => 'server', 'status' => 'error', 'error' => 'HTTP 503: outage']);

        $this->get('/admin/ai-calls')->assertOk();

        Livewire::test(ManageAiCalls::class)
            ->assertCanSeeTableRecords([$ok, $failed])
            ->assertSee('สร้างแบบฝึก')
            ->assertSee('HTTP 503: outage')
            ->filterTable('status', 'error')
            ->assertCanSeeTableRecords([$failed])
            ->assertCanNotSeeTableRecords([$ok]);
    }
}
