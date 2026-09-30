<?php

namespace Tests\Feature\Analysis;

use App\Models\AiCall;
use App\Models\Skill;
use App\Models\StudentAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Review\ReviewFixtures;
use Tests\TestCase;

/**
 * DESIGN §20.5: publishing computes the strengths and areas right away (by
 * code, no Gemini call); the classroom has no course, so its scope is the
 * skills of its questions.
 */
class AnalysisOnPublishTest extends TestCase
{
    use RefreshDatabase;
    use ReviewFixtures;

    public function test_publishing_writes_the_code_computed_analysis(): void
    {
        $this->makeReviewWorld(2);
        $subject = $this->assignment->subject_id;
        $s1 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน', 'grade_level' => 5]);
        $s2 = Skill::factory()->create(['subject_id' => $subject, 'code' => 'ค 1.1 ป.5/2', 'name' => 'ทศนิยม', 'grade_level' => 5]);
        $this->q['q1']->skills()->attach($s1->id);
        $this->q['q2']->skills()->attach([$s1->id, $s2->id]);
        $this->q['q3']->skills()->attach($s2->id);

        $student = $this->students[0];
        $answers = $this->answerSheet($student);
        $submission = $this->submission($student);
        $this->reviewAll($submission);
        $answers['q1']->forceFill(['final_score' => 2])->save();
        $this->assertSame(0, StudentAnalysis::query()->count());

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/publish")->assertOk();

        // s1: 1.0 then 0.5 -> 0.85 (strength); s2: 0.5 (area). See ObservationsOnPublishTest.
        $row = StudentAnalysis::query()->sole();
        $this->assertSame([$student->id, $this->classroom->id, StudentAnalysis::STATUS_COMPUTED], [$row->student_id, $row->classroom_id, $row->status]);
        $this->assertSame([['skill_id' => $s1->id, 'value' => 0.85, 'n_obs' => 2]], $row->strengths);
        $this->assertSame([['skill_id' => $s2->id, 'value' => 0.5, 'n_obs' => 2]], $row->areas);
        $this->assertNull($row->generated_input_hash);
        $this->assertSame(0, AiCall::query()->where('purpose', 'student_analysis')->count());
    }
}
