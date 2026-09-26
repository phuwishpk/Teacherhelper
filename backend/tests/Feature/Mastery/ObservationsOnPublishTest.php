<?php

namespace Tests\Feature\Mastery;

use App\Events\SubmissionPublished;
use App\Events\SubmissionReopened;
use App\Models\Appeal;
use App\Models\Mastery;
use App\Models\Response;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Review\ReviewFixtures;
use Tests\TestCase;

/**
 * DESIGN §14.2: only published answers become skill_observations (one per
 * skill of the question); mastery is the EWMA over them; an accepted appeal
 * that changes a score recomputes; republishing never duplicates. Plus the
 * mastery endpoints of §9.6 / §9.7 on top of that data.
 */
class ObservationsOnPublishTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    private Skill $s1;

    private Skill $s2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeReviewWorld(2);
        $subject = $this->assignment->subject_id;
        $this->s1 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน', 'grade_level' => 5]);
        $this->s2 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/2', 'name' => 'ทศนิยม', 'grade_level' => 5]);
        $this->q['q1']->skills()->attach($this->s1->id);
        $this->q['q2']->skills()->attach([$this->s1->id, $this->s2->id]);
        $this->q['q3']->skills()->attach($this->s2->id);
    }

    /** student 1: q1 full marks (1.0), q2 half (0.5), q3 half (0.5), q4 half (no skill). */
    private function publishStudentOne(): Submission
    {
        $student = $this->students[0];
        $answers = $this->answerSheet($student);
        $submission = $this->submission($student);
        $this->reviewAll($submission);
        $answers['q1']->forceFill(['final_score' => 2])->save();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        return $submission->refresh();
    }

    public function test_publishing_writes_one_observation_per_skill_and_the_ewma(): void
    {
        $this->answerSheet($this->students[1]); // never published: no observations
        $this->assertSame(0, SkillObservation::query()->count());

        $submission = $this->publishStudentOne();
        $student = $this->students[0];

        $rows = SkillObservation::query()->orderBy('id')->get();
        $this->assertCount(4, $rows);
        $this->assertSame(
            [[$this->s1->id, 1.0], [$this->s1->id, 0.5], [$this->s2->id, 0.5], [$this->s2->id, 0.5]],
            $rows->map(fn (SkillObservation $o) => [$o->skill_id, $o->score_ratio])->all(),
        );
        $this->assertTrue($rows->every(fn (SkillObservation $o) => $o->student_id === $student->id && $o->source === 'homework'));
        $this->assertSame($submission->published_at->timestamp, $rows[0]->observed_at->timestamp);

        // s1: 1.0 then 0.5 -> 0.3·0.5 + 0.7·1.0 = 0.85; s2: 0.5, 0.5 -> 0.5
        $this->assertSame(0.85, Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s1->id)->value('value') + 0.0);
        $this->assertSame(0.5, Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s2->id)->value('value') + 0.0);
        $this->assertSame([2, 2], Mastery::query()->where('student_id', $student->id)->orderBy('skill_id')->pluck('n_obs')->all());
        $this->assertSame(0, Mastery::query()->where('student_id', $this->students[1]->id)->count());
    }

    public function test_the_rows_are_written_after_the_publish_transaction_committed(): void
    {
        $outer = DB::transactionLevel(); // the test's own wrapping transaction
        $levels = [];
        Event::listen(SubmissionPublished::class, function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $this->publishStudentOne();

        $this->assertSame([$outer], $levels, 'SubmissionPublished is ShouldDispatchAfterCommit: its listeners run after Publisher\'s transaction, not inside it');
        $this->assertSame(4, SkillObservation::query()->count());
    }

    public function test_a_confirmed_rescan_removes_the_rows_until_the_next_publish(): void
    {
        $submission = $this->publishStudentOne();
        $student = $this->students[0];
        $this->assertSame(2, Mastery::query()->where('student_id', $student->id)->count());

        // ScanIngestor: SubmissionStatus::refresh(reopen) clears published_at, then SubmissionReopened after the commit.
        $submission->forceFill(['status' => Submission::STATUS_GRADING, 'published_at' => null, 'published_by' => null])->save();
        SubmissionReopened::dispatch($submission->id, $submission->assignment_id, $submission->student_id);

        $this->assertSame(0, SkillObservation::query()->count(), 'only published results count (§14.2)');
        $this->assertSame(0, Mastery::query()->where('student_id', $student->id)->count());
        $this->asUser($student)->getJson('/api/v1/student/mastery')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.weaknesses', []);

        // Published again: the rows come back from the (re-reviewed) answers.
        $submission->forceFill(['status' => Submission::STATUS_REVIEWED])->save();
        $this->travel(1)->days();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();
        $this->assertSame(4, SkillObservation::query()->count());
        $this->assertSame(0.85, (float) Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s1->id)->value('value'));
    }

    public function test_the_student_sees_their_mastery_weakest_first(): void
    {
        $this->publishStudentOne();

        $this->asUser($this->students[0])->getJson('/api/v1/student/mastery')
            ->assertOk()
            ->assertJsonPath('meta.available', true)
            ->assertJsonPath('meta.weaknesses', [$this->s2->id, $this->s1->id])
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.skill.code', 'ค 1.1 ป.5/2')
            ->assertJsonPath('data.0.value', 0.5)
            ->assertJsonPath('data.0.n_obs', 2)
            ->assertJsonPath('data.0.level', 'partial')
            ->assertJsonPath('data.1.skill_id', $this->s1->id)
            ->assertJsonPath('data.1.level', 'good');

        $this->asUser($this->students[1])->getJson('/api/v1/student/mastery')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.weaknesses', []);
        $this->asUser($this->teacher)->getJson('/api/v1/student/mastery')->assertForbidden();
    }

    public function test_an_accepted_appeal_with_a_new_score_recomputes_mastery(): void
    {
        $this->publishStudentOne();
        $student = $this->students[0];
        $q2 = Response::query()->where('question_id', $this->q['q2']->id)->where('submission_id', $this->submission($student)->id)->firstOrFail();

        $appealId = $this->asUser($student)->postJson("/api/v1/student/responses/{$q2->id}/appeal", ['reason' => 'ตอบถูกแล้ว'])->assertCreated()->json('data.id');
        // Rejected: nothing moves.
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appealId}", ['status' => 'rejected', 'teacher_note' => 'ไม่ถูก'])->assertOk();
        $this->assertSame(0.85, (float) Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s1->id)->value('value'));

        Appeal::query()->whereKey($appealId)->update(['status' => 'open', 'resolved_at' => null, 'resolved_by' => null]);
        $this->asUser($this->teacher)->patchJson("/api/v1/appeals/{$appealId}", ['status' => 'accepted', 'final_score' => 2])->assertOk();

        // s1: 1.0, 1.0 -> 1.0; s2: q2 1.0 then q3 0.5 -> 0.3·0.5 + 0.7·1.0 = 0.85
        $this->assertSame(1.0, (float) Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s1->id)->value('value'));
        $this->assertSame(0.85, (float) Mastery::query()->where('student_id', $student->id)->where('skill_id', $this->s2->id)->value('value'));
        $this->assertSame(4, SkillObservation::query()->count(), 'rows are replaced, not added');
        $this->assertSame(1.0, (float) SkillObservation::query()->where('response_id', $q2->id)->where('skill_id', $this->s1->id)->value('score_ratio'));
    }

    public function test_republishing_replaces_the_rows_instead_of_duplicating_them(): void
    {
        $submission = $this->publishStudentOne();
        $first = SkillObservation::query()->pluck('id')->all();

        // A confirmed rescan reopens the submission (SubmissionStatus); the teacher publishes again later.
        $submission->forceFill(['status' => Submission::STATUS_REVIEWED, 'published_at' => null])->save();
        $this->travel(1)->days();
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        $this->assertSame(4, SkillObservation::query()->count());
        $this->assertSame([], array_intersect($first, SkillObservation::query()->pluck('id')->all()));
        $this->assertSame(2, Mastery::query()->where('student_id', $this->students[0]->id)->count());
        $this->assertSame(0.85, (float) Mastery::query()->where('student_id', $this->students[0]->id)->where('skill_id', $this->s1->id)->value('value'));
    }

    public function test_the_teacher_reads_a_students_mastery_and_the_classroom_heatmap(): void
    {
        $this->publishStudentOne();
        [$s1, $s2] = $this->students;

        $this->asUser($this->teacher)->getJson("/api/v1/students/{$s1->id}/mastery")
            ->assertOk()
            ->assertJsonPath('student.id', $s1->id)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.skill_id', $this->s2->id)
            ->assertJsonPath('meta.weaknesses', [$this->s2->id, $this->s1->id]);

        $heat = $this->asUser($this->teacher)->getJson("/api/v1/classrooms/{$this->classroom->id}/mastery")->assertOk();
        $heat->assertJsonPath('data.classroom_id', $this->classroom->id)
            ->assertJsonCount(2, 'data.skills')
            ->assertJsonPath('data.skills.0.code', 'ค 1.1 ป.5/1')
            ->assertJsonCount(2, 'data.students')
            ->assertJsonPath('data.students.0.student_number', 1)
            ->assertJsonPath('data.students.1.id', $s2->id)
            ->assertJsonCount(2, 'data.cells');
        $cell = collect($heat->json('data.cells'))->firstWhere('skill_id', $this->s1->id);
        $this->assertSame([$s1->id, 0.85, 2, 'good'], [$cell['student_id'], $cell['value'], $cell['n_obs'], $cell['level']]);

        // Another teacher of the same school does not teach this classroom: 404.
        $other = $this->makeTeacher($this->teacher->school);
        $this->asUser($other)->getJson("/api/v1/students/{$s1->id}/mastery")->assertNotFound();
        $this->asUser($other)->getJson("/api/v1/classrooms/{$this->classroom->id}/mastery")->assertNotFound();
        $this->asUser($s1)->getJson("/api/v1/classrooms/{$this->classroom->id}/mastery")->assertForbidden();
    }
}
