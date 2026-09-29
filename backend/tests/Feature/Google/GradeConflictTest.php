<?php

namespace Tests\Feature\Google;

use App\Domain\Google\ClassroomGradePusher;
use App\Domain\Notifications\Notifier;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\GradeConflict;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\SkillObservation;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Review\ReviewFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * "คะแนนไม่ตรงกัน" (DESIGN §19.3): the app's total is the real one. A sync
 * stores Classroom's assignedGrade and compares it with what the app pushed
 * (courseWork the app posted) or with the published total (courseWork
 * created on the Classroom website); the teacher pushes the app's total,
 * takes Classroom's (total_override) or dismisses, and a resolved
 * difference does not come back. Google is Http::fake.
 */
class GradeConflictTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use ReviewFixtures;

    /** assignedGrade Classroom answers for sub-1 (null = empty); false = sub-1 not listed */
    private float|int|null|false $classroomGrade = false;

    private Submission $submission;

    protected function setUp(): void
    {
        parent::setUp();
        $this->configureGoogle();
        $this->captureLogs();
        $this->app->instance(Notifier::class, new RecordingNotifier);
        $this->makeReviewWorld(2);
        $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        AssignmentGoogleLink::create(['assignment_id' => $this->assignment->id, 'course_work_id' => self::COURSE_WORK_ID, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        ClassroomStudent::query()->where('student_id', $this->students[0]->id)->update(['google_user_id' => 'g-1']);

        $this->fakeGoogle([
            $this->submissionsUrl().'/sub-1:return' => Http::response([]),
            $this->submissionsUrl().'/sub-1?*' => function (Request $request) {
                $this->classroomGrade = $request['assignedGrade'];

                return Http::response(['id' => 'sub-1', 'state' => 'RETURNED', 'assignedGrade' => $request['assignedGrade'], 'updateTime' => 'T2']);
            },
            $this->submissionsUrl().'*' => function () {
                if ($this->classroomGrade === false) {
                    return Http::response(['studentSubmissions' => []]);
                }
                $row = self::turnedIn('sub-1', 'g-1', [['f-1', 'IMG_1.jpg']]);
                $row['state'] = 'RETURNED';
                if ($this->classroomGrade !== null) {
                    $row['assignedGrade'] = $this->classroomGrade;
                }

                return Http::response(['studentSubmissions' => [$row]]);
            },
        ]);

        // Student 1 handed in on Classroom, was graded and published: the app pushed the total.
        $this->answerSheet($this->students[0]);
        $this->submission = $this->submission($this->students[0]);
        $this->reviewAll($this->submission->load('responses'));
        ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignment->id, 'google_submission_id' => 'sub-1', 'google_user_id' => 'g-1',
            'student_id' => $this->students[0]->id, 'state' => ClassroomSubmissionImport::STATE_IMPORTED,
            'attachments' => [['drive_file_id' => 'f-1', 'title' => 'IMG_1.jpg', 'mime_type' => 'image/jpeg']],
            'google_update_time' => '2026-09-20T02:15:00.000Z',
        ]);
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$this->submission->id}/publish")->assertOk();
        $this->submission->refresh();
    }

    private function submissionsUrl(): string
    {
        return 'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork/'.self::COURSE_WORK_ID.'/studentSubmissions';
    }

    private function import(): ClassroomSubmissionImport
    {
        return ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-1')->sole();
    }

    private function sync(): void
    {
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")->assertOk();
    }

    private function resolve(GradeConflict $conflict, string $action): TestResponse
    {
        return $this->asUser($this->teacher)->postJson("/api/v1/grade-conflicts/{$conflict->id}/resolve", ['action' => $action]);
    }

    private function total(): float
    {
        return (float) $this->submission->total_score;
    }

    public function test_publishing_records_the_pushed_grade_and_an_unchanged_grade_is_no_conflict(): void
    {
        $import = $this->import();
        $this->assertSame([ClassroomSubmissionImport::STATE_GRADED, $this->total(), $this->total()], [$import->state, $import->pushed_grade, $import->classroom_grade]);

        $this->sync();
        $this->assertSame(0, GradeConflict::query()->count());
        $this->assertSame($this->total(), $this->import()->classroom_grade);
    }

    public function test_a_grade_changed_in_classroom_opens_one_conflict(): void
    {
        $this->classroomGrade = $this->total() - 1;
        $this->sync();
        $this->sync();

        $conflict = GradeConflict::query()->sole();
        $this->assertSame([GradeConflict::STATUS_OPEN, $this->total(), $this->total() - 1, $this->submission->id], [$conflict->status, $conflict->app_score, $conflict->classroom_score, $conflict->submission_id]);

        // Changed again while open: the same row shows the latest values.
        $this->classroomGrade = $this->total() - 2;
        $this->sync();
        $this->assertSame($this->total() - 2, GradeConflict::query()->sole()->classroom_score);

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/grade-conflicts")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'open')
            ->assertJsonPath('data.0.student.student_number', 1)
            ->assertJsonPath('data.0.can_push_app', true)
            ->assertJsonPath('data.0.classroom_score', fn ($v) => (float) $v === $this->total() - 2);
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")
            ->assertJsonPath('data.0.pushed_grade', fn ($v) => (float) $v === $this->total())
            ->assertJsonPath('data.0.classroom_grade', fn ($v) => (float) $v === $this->total() - 2);

        // Set back on the website: nothing left to resolve.
        $this->classroomGrade = $this->total();
        $this->sync();
        $this->assertSame(0, GradeConflict::query()->count());
    }

    public function test_an_empty_grade_on_either_side_is_no_conflict(): void
    {
        $this->classroomGrade = null;
        $this->sync();
        $this->assertNull($this->import()->classroom_grade);
        $this->assertSame(0, GradeConflict::query()->count());

        // Never pushed (e.g. the push failed): nothing to compare with.
        ClassroomSubmissionImport::query()->update(['pushed_grade' => null]);
        $this->classroomGrade = 1;
        $this->sync();
        $this->assertSame(0, GradeConflict::query()->count());
    }

    public function test_push_app_sends_the_app_total_again_and_the_conflict_does_not_return(): void
    {
        $this->classroomGrade = 1;
        $this->sync();
        $conflict = GradeConflict::query()->sole();

        $this->resolve($conflict, 'push_app')
            ->assertOk()
            ->assertJsonPath('data.status', GradeConflict::STATUS_PUSHED_APP)
            ->assertJsonPath('data.resolved_by', $this->teacher->id);

        $patches = $this->sentTo('/studentSubmissions/sub-1?', 'PATCH');
        $this->assertEquals($this->total(), end($patches)['assignedGrade']);
        $this->assertSame([$this->total(), $this->total()], [$this->import()->pushed_grade, $this->import()->classroom_grade]);

        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count());
        $this->resolve($conflict, 'dismiss')->assertStatus(409)->assertJsonPath('code', 'conflict_resolved');
    }

    public function test_accept_classroom_overrides_the_total_but_not_the_questions_or_mastery(): void
    {
        $observations = SkillObservation::query()->count();
        $this->classroomGrade = 3.5;
        $this->sync();
        $conflict = GradeConflict::query()->sole();

        $this->resolve($conflict, 'accept_classroom')
            ->assertOk()
            ->assertJsonPath('data.status', GradeConflict::STATUS_ACCEPTED_CLASSROOM)
            ->assertJsonPath('data.reason', 'รับคะแนนจาก Classroom')
            ->assertJsonPath('data.app_score', fn ($v) => (float) $v === $this->total())
            ->assertJsonPath('data.classroom_score', 3.5);

        $submission = $this->submission->refresh();
        $this->assertSame([3.5, $this->total(), 3.5], [$submission->total_override, $submission->total_score, $submission->effectiveTotal()]);
        $this->assertSame(3.5, $this->import()->pushed_grade);
        $this->assertSame($observations, SkillObservation::query()->count(), 'mastery is not touched');
        $this->assertSame(0, ScoreEvent::query()->where('reason', 'like', '%Classroom%')->count(), 'no per-question event');

        // The student sees the taken total; the conflict does not come back.
        $this->asUser($this->students[0])->getJson("/api/v1/student/results/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.total_score', 3.5)
            ->assertJsonPath('data.total_overridden', true);
        // The teacher's review screens know, to warn before a change clears it.
        $first = Response::query()->where('submission_id', $submission->id)->orderBy('id')->firstOrFail();
        $this->asUser($this->teacher)->getJson("/api/v1/responses/{$first->id}")
            ->assertOk()
            ->assertJsonPath('data.total_overridden', true);
        $this->asUser($this->students[0])->postJson("/api/v1/student/responses/{$first->id}/appeal", ['reason' => 'ช่วยดูอีกที'])->assertCreated();
        $this->asUser($this->teacher)->getJson('/api/v1/appeals?status=open')
            ->assertOk()
            ->assertJsonPath('data.0.total_overridden', true);
        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count());

        // A later push sends the effective total.
        $this->assertSame(ClassroomGradePusher::GRADED, app(ClassroomGradePusher::class)->push($submission->id));
        $patches = $this->sentTo('/studentSubmissions/sub-1?', 'PATCH');
        $this->assertEquals(3.5, end($patches)['assignedGrade']);

        // A confirmation changes nothing; a question whose score changes (an
        // accepted appeal here) makes the total the sum of the questions again.
        $response = Response::query()->where('submission_id', $submission->id)->orderBy('id')->firstOrFail();
        $event = ['response_id' => $response->id, 'actor' => ScoreEvent::ACTOR_TEACHER, 'actor_user_id' => $this->teacher->id, 'old_score' => 1, 'old_understanding' => 'partial', 'new_understanding' => 'partial'];
        ScoreEvent::create([...$event, 'action' => ScoreEvent::ACTION_APPEAL_REJECTED, 'new_score' => 1]);
        ScoreEvent::create([...$event, 'action' => ScoreEvent::ACTION_OVERRIDE, 'new_score' => 1]);
        $this->assertSame(3.5, $submission->refresh()->total_override);
        ScoreEvent::create([...$event, 'action' => ScoreEvent::ACTION_APPEAL_ACCEPTED, 'new_score' => 2]);
        $this->assertNull($submission->refresh()->total_override);
    }

    public function test_dismiss_is_remembered_until_a_value_changes(): void
    {
        $this->classroomGrade = 2;
        $this->sync();
        $this->resolve(GradeConflict::query()->sole(), 'dismiss')
            ->assertOk()
            ->assertJsonPath('data.status', GradeConflict::STATUS_DISMISSED)
            ->assertJsonPath('data.classroom_score', 2);
        $this->assertNull($this->submission->refresh()->total_override);

        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count());

        $this->classroomGrade = 1;
        $this->sync();
        $this->assertSame(2, GradeConflict::query()->count());
        $this->assertSame(GradeConflict::STATUS_OPEN, GradeConflict::query()->orderByDesc('id')->first()->status);
    }

    public function test_web_coursework_compares_the_published_total_and_cannot_push(): void
    {
        AssignmentGoogleLink::query()->whereKey($this->assignment->id)->update(['origin' => AssignmentGoogleLink::ORIGIN_CLASSROOM_WEB]);
        ClassroomSubmissionImport::query()->update(['pushed_grade' => null, 'state' => ClassroomSubmissionImport::STATE_IMPORTED]);

        $this->classroomGrade = 1;
        $this->sync();
        $conflict = GradeConflict::query()->sole();
        $this->assertSame([$this->total(), 1.0], [$conflict->app_score, $conflict->classroom_score]);

        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/grade-conflicts")->assertJsonPath('data.0.can_push_app', false);
        $this->resolve($conflict, 'push_app')->assertStatus(409)->assertJsonPath('code', 'coursework_not_owned');
        $this->assertSame(GradeConflict::STATUS_OPEN, $conflict->refresh()->status);

        $this->resolve($conflict, 'accept_classroom')->assertOk();
        $this->assertSame(1.0, $this->submission->refresh()->total_override);
        $this->assertNull($this->import()->pushed_grade);
        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count(), 'the effective total now equals Classroom\'s');
    }

    public function test_an_unknown_action_or_another_teacher_is_refused(): void
    {
        $this->classroomGrade = 1;
        $this->sync();
        $conflict = GradeConflict::query()->sole();

        $this->resolve($conflict, 'shrug')->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson("/api/v1/grade-conflicts/{$conflict->id}/resolve", ['action' => ['dismiss']])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->makeTeacher())->postJson("/api/v1/grade-conflicts/{$conflict->id}/resolve", ['action' => 'dismiss'])->assertNotFound();
        $this->asUser($this->makeTeacher())->getJson("/api/v1/assignments/{$this->assignment->id}/grade-conflicts")->assertNotFound();
        $this->assertSame(GradeConflict::STATUS_OPEN, $conflict->refresh()->status);
    }

    public function test_an_open_conflict_goes_away_when_one_side_becomes_empty(): void
    {
        $this->classroomGrade = 1;
        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count());

        // The teacher cleared the grade in Classroom: nothing left to compare.
        $this->classroomGrade = null;
        $this->sync();
        $this->assertSame(0, GradeConflict::query()->count());

        // A difference again opens a new row.
        $this->classroomGrade = 1;
        $this->sync();
        $this->assertSame(1, GradeConflict::query()->count());
    }

    public function test_accept_classroom_is_refused_on_a_stale_conflict(): void
    {
        $this->classroomGrade = 1;
        $this->sync();
        $conflict = GradeConflict::query()->sole();

        // Classroom's grade is empty now, before the next sync dropped the row.
        ClassroomSubmissionImport::query()->update(['classroom_grade' => null]);
        $this->resolve($conflict, 'accept_classroom')->assertStatus(409)->assertJsonPath('code', 'conflict_resolved');
        $this->assertNull($this->submission->refresh()->total_override);

        // The work is being graded again (not published).
        ClassroomSubmissionImport::query()->update(['classroom_grade' => 1]);
        Submission::query()->whereKey($this->submission->id)->update(['status' => Submission::STATUS_NEEDS_REVIEW]);
        $this->resolve($conflict, 'accept_classroom')->assertStatus(409)->assertJsonPath('code', 'conflict_resolved');
        $this->assertNull($this->submission->refresh()->total_override);
        $this->assertSame(GradeConflict::STATUS_OPEN, $conflict->refresh()->status);
    }
}
