<?php

namespace Tests\Feature\Review;

use App\Domain\Training\TrainingSamples;
use App\Models\TrainingSample;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §8.6, §12.3: an overridden numeric answer becomes a training
 * sample (crop copied out of the retention policy, label, hashed writer
 * key) only in schools with allow_training_data = TRUE.
 */
class TrainingSamplesTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeReviewWorld(1);
        $this->teacher->school->forceFill(['allow_training_data' => true])->save();
    }

    public function test_a_full_marks_override_of_a_numeric_answer_is_labelled_with_the_key(): void
    {
        $response = $this->answer($this->students[0], 'q1'); // numeric short, key 20, AI 1/2
        Storage::disk('local')->put($response->crop_path, 'RIFF-webp-crop');

        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
            'final_score' => 2, 'final_understanding' => 'good', 'reason' => 'AI อ่านผิด',
        ])->assertOk();

        $sample = TrainingSample::query()->sole();
        $expectedPath = "training/{$this->assignment->school_id}/{$response->id}.webp";
        $this->assertSame(['teacher_correction', '20', $expectedPath, $this->assignment->school_id, $response->id], [$sample->source, $sample->label, $sample->crop_path, $sample->school_id, $sample->response_id]);
        $this->assertSame(hash('sha256', 'student:'.$this->students[0]->id), $sample->writer_key);
        $this->assertSame('RIFF-webp-crop', Storage::disk('local')->get($expectedPath));

        // The teacher's own reading wins over the inferred label, Thai digits included; one row per response.
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
            'final_score' => 1, 'final_understanding' => 'partial', 'reason' => 'ใกล้เคียง', 'answer_text' => '๒๐.๕',
        ])->assertOk();
        $this->assertSame(1, TrainingSample::query()->count());
        $this->assertSame('20.5', $sample->refresh()->label);
    }

    public function test_nothing_is_recorded_without_consent_a_crop_or_a_label(): void
    {
        $response = $this->answer($this->students[0], 'q1');
        Storage::disk('local')->put($response->crop_path, 'RIFF');

        // A partial override without a reading has no label.
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", ['final_score' => 1.5, 'final_understanding' => 'partial', 'reason' => 'x'])->assertOk();
        // A reading that is not a digit string is dropped.
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", ['final_score' => 2, 'final_understanding' => 'good', 'reason' => 'x', 'answer_text' => 'twenty'])->assertOk();
        $this->assertSame(0, TrainingSample::query()->count());

        // Text questions never produce samples.
        $text = $this->answer($this->students[0], 'q2');
        Storage::disk('local')->put($text->crop_path, 'RIFF');
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$text->id}", ['final_score' => 2, 'final_understanding' => 'good', 'reason' => 'x'])->assertOk();
        $this->assertSame(0, TrainingSample::query()->count());

        // No consent: nothing, even with a reading.
        $this->teacher->school->forceFill(['allow_training_data' => false])->save();
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", ['final_score' => 2, 'final_understanding' => 'good', 'reason' => 'x', 'answer_text' => '20'])->assertOk();
        $this->assertSame(0, TrainingSample::query()->count());
        Storage::disk('local')->assertMissing("training/{$this->assignment->school_id}/{$response->id}.webp");
    }

    public function test_show_work_uses_the_final_answer_box(): void
    {
        $work = $this->answer($this->students[0], 'q3', ['final_crop_path' => "crops/{$this->assignment->school_id}/{$this->assignment->id}/x-q3-final.webp"]);
        Storage::disk('local')->put($work->crop_path, 'whole');
        Storage::disk('local')->put($work->final_crop_path, 'final-box');

        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$work->id}", ['final_score' => 5, 'final_understanding' => 'good', 'reason' => 'x'])->assertOk();

        $sample = TrainingSample::query()->sole();
        $this->assertSame('5', $sample->label);
        $this->assertSame('final-box', Storage::disk('local')->get($sample->crop_path));

        $this->assertSame('3/4', TrainingSamples::cleanLabel(' ๓/๔ '));
        $this->assertSame('-2.5', TrainingSamples::cleanLabel('−2,5'));
        $this->assertNull(TrainingSamples::cleanLabel('x = 5'));
    }
}
