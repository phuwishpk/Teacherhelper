<?php

namespace Tests\Feature\Review;

use App\Models\Response;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * GET /student/results[/{submission_id}] (DESIGN §9.7, §13): a student sees
 * only their own submissions, only once published, and only final values.
 */
class StudentResultsTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private Submission $mine;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeReviewWorld(2);
        [$s1, $s2] = $this->students;

        $this->answer($s1, 'q3', ['final_crop_path' => 'crops/x/final.webp', 'extraction' => ['blank' => false, 'transcription' => 'x'], 'fuzzy_trace' => ['system' => 'show_work', 'priority' => ['p' => 0.3]]]);
        $this->answer($s1, 'q1');
        $this->mine = $this->submission($s1);
        $this->reviewAll($this->mine);
        Response::query()->where('question_id', $this->q['q3']->id)->update(['final_score' => 4, 'final_understanding' => 'good', 'final_error_types' => json_encode(['careless'])]);
        $this->mine->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 5])->save();

        $this->answer($s2, 'q1');
        $theirs = $this->submission($s2);
        $this->reviewAll($theirs);
        $theirs->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'total_score' => 1])->save();
    }

    public function test_the_list_holds_only_the_students_published_results(): void
    {
        $unpublished = $this->assignment->replicate(['status'])->fill(['title' => 'ยังไม่เผยแพร่', 'status' => 'ready']);
        $unpublished->save();
        Submission::create(['assignment_id' => $unpublished->id, 'student_id' => $this->students[0]->id, 'status' => Submission::STATUS_REVIEWED]);

        $this->asUser($this->students[0])->getJson('/api/v1/student/results')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.submission_id', $this->mine->id)
            ->assertJsonPath('data.0.title', 'การบ้านเศษส่วน')
            ->assertJsonPath('data.0.total_score', 5)
            ->assertJsonPath('data.0.max_score', 10)
            ->assertJsonPath('data.0.assignment.subject.name', $this->assignment->subject->name)
            ->assertJsonPath('data.0.subject_name', $this->assignment->subject->name);
    }

    public function test_the_detail_shows_final_values_and_crop_links_only(): void
    {
        $res = $this->asUser($this->students[0])->getJson("/api/v1/student/results/{$this->mine->id}")->assertOk();

        $res->assertJsonPath('data.total_score', 5)
            ->assertJsonPath('data.max_score', 10)
            ->assertJsonCount(2, 'data.responses')
            ->assertJsonPath('data.responses.0.position', (int) $this->q['q1']->position)
            ->assertJsonPath('data.responses.1.type', 'show_work')
            ->assertJsonPath('data.responses.1.final_score', 4)
            ->assertJsonPath('data.responses.1.final_understanding', 'good')
            ->assertJsonPath('data.responses.1.final_error_types', ['careless'])
            ->assertJsonPath('data.responses.1.explanation', 'ลองตรวจการคำนวณอีกครั้งนะ')
            ->assertJsonPath('data.responses.1.has_final_crop', true)
            ->assertJsonPath('data.responses.1.final_crop_url', '/api/v1/responses/'.$res->json('data.responses.1.id').'/crop?part=final')
            ->assertJsonPath('data.responses.1.appeal', null)
            ->assertJsonPath('data.responses.1.can_appeal', true);

        $body = json_encode($res->json());
        foreach (['ai_score', 'extraction', 'fuzzy_trace', 'review_priority', 'priority_band', 'transcription', 'manual_reason'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $body, "{$hidden} is for the teacher only");
        }

        $crop = $res->json('data.responses.0.crop_url');
        Storage::disk('local')->put(Response::query()->find($res->json('data.responses.0.id'))->crop_path, 'RIFF-webp');
        $this->asUser($this->students[0])->get($crop)->assertOk();
        $this->asUser($this->students[1])->get($crop)->assertForbidden();
    }

    public function test_other_students_and_unpublished_results_are_404(): void
    {
        $this->asUser($this->students[1])->getJson("/api/v1/student/results/{$this->mine->id}")->assertNotFound();

        $this->mine->forceFill(['status' => Submission::STATUS_REVIEWED, 'published_at' => null])->save();
        $this->asUser($this->students[0])->getJson("/api/v1/student/results/{$this->mine->id}")->assertNotFound();
        $this->asUser($this->students[0])->getJson('/api/v1/student/results')->assertJsonCount(0, 'data');
    }

    public function test_teachers_do_not_use_the_student_endpoints(): void
    {
        $this->asUser($this->teacher)->getJson('/api/v1/student/results')->assertForbidden();
        $this->asUser($this->teacher)->getJson("/api/v1/student/results/{$this->mine->id}")->assertForbidden();
        $this->asGuest()->getJson('/api/v1/student/results')->assertUnauthorized();
    }

    public function test_mastery_is_an_empty_placeholder_for_now(): void
    {
        $this->asUser($this->students[0])->getJson('/api/v1/student/mastery')
            ->assertOk()
            ->assertExactJson(['data' => [], 'meta' => ['available' => false]]);
    }
}
