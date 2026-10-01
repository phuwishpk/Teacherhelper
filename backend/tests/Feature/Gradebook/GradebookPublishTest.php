<?php

namespace Tests\Feature\Gradebook;

use App\Domain\Notifications\Notifier;
use App\Domain\Notifications\PushMessage;
use App\Events\GradesPublished;
use App\Listeners\NotifyStudentsOfPublishedGrades;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/** DESIGN §23.7: publishing a classroom's grades, the snapshot, staleness, withdrawal and what students see. */
class GradebookPublishTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    private RecordingNotifier $notifier;

    private int $itemId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld();
        $this->notifier = new RecordingNotifier;
        $this->app->instance(Notifier::class, $this->notifier);
    }

    public function test_publishing_needs_a_configured_and_complete_gradebook(): void
    {
        $url = "/api/v1/courses/{$this->course->id}/gradebook/publish";
        $this->asUser($this->teacher)->postJson($url, ['classroom_id' => $this->classroom->id])
            ->assertStatus(409)->assertJsonPath('code', 'gradebook_not_configured');

        $cats = $this->useTemplate('collect_final');
        $this->scoreAll($cats['คะแนนเก็บ']->id);
        $this->asUser($this->teacher)->postJson($url, ['classroom_id' => $this->classroom->id])
            ->assertStatus(422)->assertJsonPath('code', 'gradebook_incomplete')->assertJsonPath('errors.categories', ['ปลายภาค']);
        $this->asUser($this->teacher)->postJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['classroom_id']);
        $this->assertSame(0, GradebookPublication::query()->count());
    }

    public function test_a_publication_is_a_snapshot_students_read_their_own_row_of(): void
    {
        $this->completeWorld();
        [$a, $b] = $this->students;
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/special-grades", [
            'classroom_id' => $this->classroom->id, 'student_id' => $b->id, 'special' => 'r', 'note' => 'รอสอบแก้',
        ])->assertOk();

        $this->asUser($a)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertNotFound();
        $this->asUser($a)->getJson('/api/v1/student/grades')->assertOk()->assertJsonPath('data', []);

        $published = $this->publish()->assertCreated()->assertJsonPath('data.student_count', 3)->json('data');

        // The push names the course, never the grade (§9.9).
        $pushes = $this->notifier->ofType(PushMessage::GRADES_PUBLISHED);
        $this->assertCount(1, $pushes);
        $this->assertSame(array_map(fn ($s) => $s->id, $this->students), $pushes[0][0]);
        $this->assertSame('ประกาศเกรด ค15101 แล้ว', $pushes[0][1]->body);
        $this->assertSame(['type' => 'grades_published', 'course_id' => (string) $this->course->id, 'classroom_id' => (string) $this->classroom->id], $pushes[0][1]->data());

        $list = $this->asUser($a)->getJson('/api/v1/student/grades')->assertOk()->json('data');
        $this->assertCount(1, $list);
        $this->assertSame(['id' => $this->course->id, 'code' => 'ค15101', 'name' => 'คณิตศาสตร์ 5'], $list[0]['course']);
        $this->assertSame(80, $list[0]['total_rounded']);
        $this->assertEquals(4, $list[0]['grade']);

        $mine = $this->asUser($a)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertOk()->json('data');
        $this->assertSame($published['publication_id'], GradebookPublication::query()->value('id'));
        $this->assertEquals(80, $mine['total']);
        $this->assertSame(['คะแนนเก็บ', 'ปลายภาค'], array_column($mine['breakdown'], 'name'));
        $this->assertSame(['name', 'score', 'max', 'percent', 'state', 'dropped'], array_keys($mine['breakdown'][0]['items'][0]));
        $this->assertArrayNotHasKey('attendance_warning', $mine);
        $this->assertStringNotContainsString('รอสอบแก้', (string) json_encode($mine));

        // ร replaces the grade; the teacher's note stays with the teacher.
        $theirs = $this->asUser($b)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertOk()->json('data');
        $this->assertNull($theirs['grade']);
        $this->assertSame('r', $theirs['special']);
        $this->assertStringNotContainsString('รอสอบแก้', (string) json_encode($theirs));
        $this->assertStringNotContainsString($a->name, (string) json_encode($theirs));

        // Another course's grade is not there.
        $other = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ว15101']);
        $this->asUser($a)->getJson("/api/v1/student/courses/{$other->id}/grade")->assertNotFound();
    }

    public function test_later_changes_leave_the_snapshot_and_mark_the_grid_stale_until_republished(): void
    {
        $this->completeWorld();
        $student = $this->students[0];
        $this->publish()->assertCreated();
        $this->assertFalse($this->grid()['publication']['stale']);

        $this->putItemScores($this->itemId, [['student_id' => $student->id, 'score' => 2]])->assertOk();

        $grid = $this->grid();
        $this->assertTrue($grid['publication']['stale']);
        $this->assertEquals(80, $this->asUser($student)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->json('data.total'));

        $this->publish()->assertCreated();
        $this->assertFalse($this->grid()['publication']['stale']);
        $this->assertEquals(38, $this->asUser($student)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->json('data.total'));
        $this->assertSame(2, GradebookPublication::query()->count());

        // Changed cutoffs make it stale too.
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/cutoffs", ['cutoffs' => [85, 80, 75, 70, 65, 60, 55]])->assertOk();
        $this->assertTrue($this->grid()['publication']['stale']);
    }

    public function test_withdrawing_shows_the_previous_publication_or_nothing(): void
    {
        $this->completeWorld();
        $student = $this->students[0];
        $url = "/api/v1/courses/{$this->course->id}/gradebook/publish?classroom_id={$this->classroom->id}";
        $this->asUser($this->teacher)->deleteJson($url)->assertNotFound();

        $this->publish()->assertCreated();
        $this->putItemScores($this->itemId, [['student_id' => $student->id, 'score' => 2]])->assertOk();
        $this->publish()->assertCreated();
        $this->assertEquals(38, $this->asUser($student)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->json('data.total'));

        $this->asUser($this->teacher)->deleteJson($url)->assertNoContent();
        $this->assertEquals(80, $this->asUser($student)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->json('data.total'));
        $this->assertCount(1, $this->asUser($student)->getJson('/api/v1/student/grades')->json('data'));

        $this->asUser($this->teacher)->deleteJson($url)->assertNoContent();
        $this->asUser($student)->getJson("/api/v1/student/courses/{$this->course->id}/grade")->assertNotFound();
        $this->asUser($student)->getJson('/api/v1/student/grades')->assertOk()->assertJsonPath('data', []);
        $this->assertNull($this->grid()['publication']);
        $this->asUser($this->teacher)->deleteJson($url)->assertNotFound();
    }

    public function test_a_withdrawn_publication_sends_no_push(): void
    {
        $this->completeWorld();
        Queue::fake();
        $id = $this->publish()->assertCreated()->json('data.publication_id');
        Queue::assertPushed(CallQueuedListener::class, fn (CallQueuedListener $job) => $job->class === NotifyStudentsOfPublishedGrades::class);
        $this->asUser($this->teacher)->deleteJson("/api/v1/courses/{$this->course->id}/gradebook/publish?classroom_id={$this->classroom->id}")->assertNoContent();

        // The cron worker runs the queued listener after the withdrawal.
        app(NotifyStudentsOfPublishedGrades::class)->handle(new GradesPublished($id));

        $this->assertSame([], $this->notifier->ofType(PushMessage::GRADES_PUBLISHED));
    }

    public function test_the_snapshot_rows_hold_the_breakdown(): void
    {
        $this->completeWorld();
        $this->publish()->assertCreated();

        $row = GradebookPublishedGrade::query()->where('student_id', $this->students[0]->id)->firstOrFail();
        $this->assertEquals(80.0, $row->total);
        $this->assertSame(80, $row->total_rounded);
        $this->assertEquals(4.0, $row->grade);
        $this->assertFalse($row->attendance_warning);
        $this->assertSame('คะแนนเก็บ', $row->breakdown[0]['name']);
        $this->assertEquals(56, $row->breakdown[0]['points']);
        $publication = GradebookPublication::query()->firstOrFail();
        $this->assertSame([80, 75, 70, 65, 60, 55, 50], $publication->cutoffs);
        $this->assertSame(['คะแนนเก็บ', 'ปลายภาค'], array_column($publication->categories, 'name'));
    }

    /**
     * collect_final with one item per category: every student has 8/10 (80 %).
     */
    private function completeWorld(): void
    {
        $cats = $this->useTemplate('collect_final');
        $this->itemId = $this->scoreAll($cats['คะแนนเก็บ']->id);
        $this->scoreAll($cats['ปลายภาค']->id);
    }

    private function scoreAll(int $categoryId): int
    {
        $id = $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook-items", [
            'classroom_ids' => [$this->classroom->id], 'category_id' => $categoryId, 'name' => 'รายการ '.$categoryId, 'max_points' => 10,
        ])->assertCreated()->json('data.0.id');
        $this->putItemScores($id, array_map(fn ($s) => ['student_id' => $s->id, 'score' => 8], $this->students))->assertOk();

        return $id;
    }

    private function publish(): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/courses/{$this->course->id}/gradebook/publish", ['classroom_id' => $this->classroom->id]);
    }
}
