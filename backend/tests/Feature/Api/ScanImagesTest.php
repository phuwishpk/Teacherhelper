<?php

namespace Tests\Feature\Api;

use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §7.3, §9.5, §9.7: scan images have no public URL. GET
 * /scans/{id}/page (teacher) and GET /responses/{id}/crop (teacher, or the
 * student once published) stream them after the policy check.
 */
class ScanImagesTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private Scan $scan;

    private Response $workResponse;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $id = $this->postScan($this->metaFor(2))->assertStatus(201)->json('scan_id');
        $this->scan = Scan::query()->findOrFail($id);
        $this->workResponse = Response::query()->where('question_id', $this->work->id)->firstOrFail();
    }

    public function test_the_teacher_downloads_the_page_image(): void
    {
        $res = $this->asUser($this->teacher)->get("/api/v1/scans/{$this->scan->id}/page")->assertOk();

        $this->assertSame('image/webp', $res->headers->get('Content-Type'));
        $this->assertStringContainsString('private', (string) $res->headers->get('Cache-Control'));
        $this->assertSame($this->fixtureBytes('page.webp'), $res->streamedContent());
    }

    public function test_the_page_image_is_limited_to_the_teacher_of_the_classroom(): void
    {
        $this->asUser($this->makeTeacher($this->assignment->school))->getJson("/api/v1/scans/{$this->scan->id}/page")
            ->assertStatus(403);
        $this->asUser($this->makeTeacher())->getJson("/api/v1/scans/{$this->scan->id}/page")
            ->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->asUser($this->student)->getJson("/api/v1/scans/{$this->scan->id}/page")->assertStatus(403);
        $this->asGuest()->getJson("/api/v1/scans/{$this->scan->id}/page")->assertStatus(401);
    }

    public function test_a_purged_page_image_is_gone(): void
    {
        Storage::disk('local')->delete($this->scan->page_image_path);
        $this->scan->update(['page_image_path' => null]);

        $this->asUser($this->teacher)->getJson("/api/v1/scans/{$this->scan->id}/page")
            ->assertStatus(410)
            ->assertJsonPath('code', 'image_purged');
    }

    public function test_the_teacher_downloads_the_crop_and_the_final_answer_crop(): void
    {
        $main = $this->asUser($this->teacher)->get("/api/v1/responses/{$this->workResponse->id}/crop")->assertOk();
        $this->assertSame('image/webp', $main->headers->get('Content-Type'));
        $this->assertSame($this->fixtureBytes('crop.webp'), $main->streamedContent());

        $final = $this->asUser($this->teacher)->get("/api/v1/responses/{$this->workResponse->id}/crop?part=final")->assertOk();
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), $final->streamedContent());

        $open = Response::query()->where('question_id', $this->open->id)->firstOrFail();
        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$open->id}/crop?part=final")->assertStatus(404);
        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$open->id}/crop?part=other")->assertStatus(422);
    }

    public function test_the_student_sees_their_own_crop_only_after_publishing(): void
    {
        // Before publishing the answer does not exist for the student: 404, like GET /student/results/{id}.
        $this->asUser($this->student)->getJson("/api/v1/responses/{$this->workResponse->id}/crop")->assertStatus(404);

        Submission::query()->whereKey($this->workResponse->submission_id)->update(['status' => 'published', 'published_at' => now()]);

        $res = $this->asUser($this->student)->get("/api/v1/responses/{$this->workResponse->id}/crop")->assertOk();
        $this->assertSame($this->fixtureBytes('crop.webp'), $res->streamedContent());

        $classmate = $this->enrollStudent($this->classroom, 13)['student'];
        $this->asUser($classmate)->getJson("/api/v1/responses/{$this->workResponse->id}/crop")->assertStatus(404);
    }

    public function test_other_teachers_cannot_read_crops(): void
    {
        $this->asUser($this->makeTeacher($this->assignment->school))->getJson("/api/v1/responses/{$this->workResponse->id}/crop")->assertStatus(403);
        $this->asUser($this->makeTeacher())->getJson("/api/v1/responses/{$this->workResponse->id}/crop")->assertStatus(404);
    }

    public function test_a_purged_crop_is_gone(): void
    {
        $this->workResponse->update(['crop_path' => null, 'final_crop_path' => null]);

        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$this->workResponse->id}/crop")
            ->assertStatus(410)
            ->assertJsonPath('code', 'image_purged');
    }
}
