<?php

namespace Tests\Feature\Exams;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Models\Assignment;
use App\Models\LessonPlan;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Response;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Submission;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.13 (Phase 10 build step 6): the option analysis of an exam
 * (counts per master question over all versions, the 27 % groups by the
 * total that counts, distractor flags), indicators of exam questions with
 * Gemini's suggestions from the lesson plan or, without a plan, from the
 * course, and published exam answers feeding mastery (source exam).
 * Gemini is the FakeGeminiClient.
 */
class ExamAnalysisTest extends TestCase
{
    use ExamSheetTestHelpers;
    use ExamTestHelpers;
    use RefreshDatabase;

    private FakeGeminiClient $gemini;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Http::preventStrayRequests();
        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
    }

    /**
     * An approved exam with two versions: two 4-option mcq (key ก), one
     * true/false (key ถูก) and one numeric (key 1), in that order.
     *
     * @return list<Question>
     */
    private function examQuestions(Assignment $exam): array
    {
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2], 'question_count' => 1]);
        $this->approveExam($exam);

        return Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get()->all();
    }

    /**
     * A student's submission with the given answers, straight in the
     * database. $answers: question index => exam_answer (null = no row).
     *
     * @param  list<Question>  $questions
     * @param  array<int, array<string, mixed>|null>  $answers
     */
    private function sheet(Assignment $exam, array $questions, User $student, array $answers, array $submission): Submission
    {
        $row = Submission::create($submission + ['assignment_id' => $exam->id, 'student_id' => $student->id, 'status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);
        foreach ($questions as $index => $question) {
            $answer = $answers[$index] ?? null;
            if ($answer === null) {
                continue;
            }
            $key = (array) $question->answer_key;
            $selected = (array) (($answer['resolved']['selected'] ?? null) ?? ($answer['selected'] ?? []));
            $right = $question->type === Question::TYPE_NUMERIC
                ? ($answer['value'] ?? null) === '1'
                : count($selected) === 1 && in_array($selected[0], (array) ($key['accepted_options'] ?? []), true);
            Response::create([
                'submission_id' => $row->id,
                'question_id' => $question->id,
                'grading_state' => Response::STATE_SCORED,
                'ai_score' => $right ? 1 : 0,
                'final_score' => $right ? 1 : 0,
                'exam_answer' => $answer + ['sheet_no' => $question->position, 'version_no' => $student->id % 2 + 1, 'selected' => [], 'value' => null, 'doubts' => []],
                'reviewed_at' => now(),
            ]);
        }

        return $row;
    }

    /**
     * 22 published students. total_score = i (student 22 is best by score)
     * but total_override = 100 − i, so by the total that counts student 1 is
     * best: top 27 % = students 1–6, bottom = 17–22 (group size 6).
     *
     * Q1: 1–6 ก, 7–20 ข, 21 blank, 22 ข+ค (multiple): ค and ง nobody.
     * Q2: 1–6 ค (a distractor the top picks), 7 read ก+ง resolved to ง,
     *     8–16 ง, 17–22 ก.
     * Q3: everyone ถูก except 22 (ผิด). Q4: 1–11 "1", 12–21 unreadable,
     *     22 has no answer row.
     *
     * @param  list<Question>  $questions
     * @return list<User>
     */
    private function publishClass(Assignment $exam, array $questions): array
    {
        $students = [];
        for ($i = 1; $i <= 22; $i++) {
            $student = $this->enrollStudent($this->classroom, $i, 'นักเรียน '.$i)['student'];
            $students[] = $student;
            $q1 = match (true) {
                $i <= 6 => ['selected' => [1]],
                $i <= 20 => ['selected' => [2]],
                $i === 21 => ['selected' => []],
                default => ['selected' => [2, 3], 'doubts' => ['double_mark']],
            };
            $q2 = match (true) {
                $i <= 6 => ['selected' => [3]],
                $i === 7 => ['selected' => [1, 4], 'doubts' => ['double_mark'], 'resolved' => ['selected' => [4], 'value' => null, 'by' => $this->teacher->id, 'at' => now()->toIso8601String()]],
                $i <= 16 => ['selected' => [4]],
                default => ['selected' => [1]],
            };
            $q3 = ['selected' => [$i === 22 ? 2 : 1]];
            $q4 = $i === 22 ? null : ['value' => $i <= 11 ? '1' : null];
            $this->sheet($exam, $questions, $student, [$q1, $q2, $q3, $q4], ['total_score' => $i, 'total_override' => 100 - $i]);
        }
        // Not published: never counts.
        $late = $this->enrollStudent($this->classroom, 23, 'นักเรียน 23')['student'];
        $this->sheet($exam, $questions, $late, [['selected' => [4]], ['selected' => [2]], ['selected' => [2]], ['value' => '7']], ['status' => Submission::STATUS_NEEDS_REVIEW, 'published_at' => null, 'total_score' => 50]);

        return $students;
    }

    public function test_options_are_counted_per_master_question_across_versions_with_distractor_flags(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $questions = $this->examQuestions($exam);
        $this->publishClass($exam, $questions);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/option-analysis")->assertOk()->json('data');
        $this->assertSame([$exam->id, 22, true, 20, 6], [$data['exam_id'], $data['published_count'], $data['groups_ready'], $data['min_count_for_r'], $data['group_size']]);
        $this->assertCount(4, $data['questions']);
        [$q1, $q2, $q3, $q4] = $data['questions'];
        $row = fn (array $o) => [$o['label'], $o['correct'], $o['count'], $o['top'], $o['bottom'], $o['flags']];

        $this->assertSame([$questions[0]->id, 1, 'mcq'], [$q1['question_id'], $q1['position'], $q1['type']]);
        $this->assertSame([
            ['ก', true, 6, 6, 0, []],
            ['ข', false, 14, 0, 4, []],
            ['ค', false, 0, 0, 0, ['unused_distractor']],
            ['ง', false, 0, 0, 0, ['unused_distractor']],
        ], array_map($row, $q1['options']));
        $this->assertEquals(['count' => 1, 'pct' => 4.5], $q1['blank']);
        $this->assertEquals(['count' => 1, 'pct' => 4.5], $q1['multiple']);
        $this->assertEquals(27.3, $q1['options'][0]['pct']);
        $this->assertEquals(63.6, $q1['options'][1]['pct']);
        // p and r of §14.3, with the groups by effectiveTotal(): the top six all right, the bottom six all wrong.
        $this->assertEquals([0.273, 1.0], [$q1['p'], $q1['r']]);
        $this->assertSame(2, $q1['flag_count']);

        // The teacher's reading (resolved) counts, not the double mark.
        $this->assertSame([
            ['ก', true, 6, 0, 6, []],
            ['ข', false, 0, 0, 0, ['unused_distractor']],
            ['ค', false, 6, 6, 0, ['reversed_distractor']],
            ['ง', false, 10, 0, 0, []],
        ], array_map($row, $q2['options']));
        $this->assertSame(0, $q2['multiple']['count']);
        $this->assertEquals(-1.0, $q2['r']);

        $this->assertSame([['ถ', true, 21, 6, 5, []], ['ผ', false, 1, 0, 1, []]], array_map($row, $q3['options']));

        // Numeric: no options; unreadable and missing answers are blank.
        $this->assertSame(['numeric', [], 11, 0], [$q4['type'], $q4['options'], $q4['blank']['count'], $q4['multiple']['count']]);
    }

    public function test_below_twenty_published_students_the_groups_and_flags_wait(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $questions = $this->examQuestions($exam);
        $this->publishClass($exam, $questions);
        Submission::query()->where('assignment_id', $exam->id)->where('status', Submission::STATUS_PUBLISHED)
            ->orderByDesc('id')->limit(3)->update(['status' => Submission::STATUS_REVIEWED, 'published_at' => null]);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/option-analysis")->assertOk()->json('data');
        $this->assertSame([19, false, null], [$data['published_count'], $data['groups_ready'], $data['group_size']]);
        foreach ($data['questions'] as $question) {
            $this->assertNull($question['r']);
            $this->assertSame(0, $question['flag_count']);
            foreach ($question['options'] as $option) {
                $this->assertSame([null, null, []], [$option['top'], $option['bottom'], $option['flags']]);
            }
        }
        // Students 1–19 on Q1: six ก, thirteen ข.
        $this->assertSame([6, 13, 0, 0], array_column($data['questions'][0]['options'], 'count'));
    }

    public function test_an_exam_nobody_was_published_for_and_who_may_read_it(): void
    {
        $exam = $this->createExam();
        $this->examQuestions($exam);
        $data = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/option-analysis")->assertOk()->json('data');
        $this->assertSame([0, false], [$data['published_count'], $data['groups_ready']]);
        $this->assertSame([0, null], [$data['questions'][0]['options'][0]['count'], $data['questions'][0]['options'][0]['pct']]);
        $this->assertSame(['count' => 0, 'pct' => null], $data['questions'][0]['blank']);

        $this->asUser($this->makeTeacher($this->teacher->school))->getJson("/api/v1/exams/{$exam->id}/option-analysis")->assertNotFound();
        $homework = Assignment::factory()->for_classroom($this->classroom)->create();
        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$homework->id}/option-analysis")->assertNotFound();
    }

    public function test_published_exam_answers_feed_mastery_as_exam_observations(): void
    {
        $student = $this->enrollStudent($this->classroom, 1, 'นักเรียน 1')['student'];
        $exam = $this->printedExam(mcq: 4);
        $skill = Skill::factory()->create(['subject_id' => $exam->subject_id, 'code' => 'ค 1.1 ป.5/1', 'name' => 'เศษส่วน']);
        $questions = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$exam->id}/indicator-mapping", ['questions' => [
            ['question_id' => $questions[0]->id, 'skill_ids' => [$skill->id]],
            ['question_id' => $questions[1]->id, 'skill_ids' => [$skill->id]],
        ]])->assertOk()->assertJsonPath('data.changed_question_count', 2);

        // Key ก everywhere (fillExam): question 1 right, question 2 wrong.
        $this->upload($exam, $student, 1, $this->reading($exam, 1, [1 => 1, 2 => 2, 3 => 1, 4 => 1]))->assertCreated();
        $this->assertSame(0, SkillObservation::query()->count(), 'nothing counts before publishing');
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/publish")->assertOk()->assertJsonPath('data.published', 1);

        $observations = SkillObservation::query()->orderBy('response_id')->get();
        $this->assertSame([SkillObservation::SOURCE_EXAM, SkillObservation::SOURCE_EXAM], $observations->pluck('source')->all());
        $this->assertEquals([1.0, 0.0], $observations->pluck('score_ratio')->all());
        // α = 0.30 like homework: 0.3·0 + 0.7·1.
        $mastery = Mastery::query()->where('student_id', $student->id)->where('skill_id', $skill->id)->sole();
        $this->assertEquals([0.7, 2], [$mastery->value, $mastery->n_obs]);

        // Remapping recorded the published answers again, still as exam observations.
        $this->asUser($this->teacher)->putJson("/api/v1/assignments/{$exam->id}/indicator-mapping", ['questions' => [
            ['question_id' => $questions[2]->id, 'skill_ids' => [$skill->id]],
        ]])->assertOk();
        $this->assertSame(3, SkillObservation::query()->where('source', SkillObservation::SOURCE_EXAM)->count());
        $this->asUser($this->teacher)->getJson("/api/v1/students/{$student->id}/indicator-progress")->assertOk()
            ->assertJsonPath('data.series.0.points.0.source', 'exam');
    }

    public function test_an_exam_without_a_plan_gets_suggestions_from_the_course_indicators(): void
    {
        $exam = $this->createExam();
        $questions = $this->examQuestions($exam);
        // Added after the key approval, so nothing was suggested on approval (see the next test).
        [$inCourse, $inUnit, $inPlan, $outside] = $this->courseIndicators();
        $url = "/api/v1/assignments/{$exam->id}/indicator-suggestions";

        $data = $this->asUser($this->teacher)->getJson($url)->assertOk()->json('data');
        $this->assertSame(['course', null, $this->course->id], [$data['indicator_source'], $data['lesson_plan'], $data['course']['id']]);
        $this->assertSame([$inCourse->code, $inUnit->code, $inPlan->code], array_column($data['plan_indicators'], 'code'));
        $this->assertSame(4, $data['unmapped_question_count']);

        $this->asUser($this->teacher)->postJson($url)->assertStatus(202)->assertJsonPath('data.status', 'done');
        $this->assertCount(1, $this->gemini->requests);
        $text = $this->gemini->requests[0]->userText;
        $this->assertStringContainsString('ครอบคลุมทุกแผนของรายวิชา ค15101 คณิตศาสตร์ 5', $text);
        $this->assertStringContainsString('- '.$inPlan->code.': ', $text);
        $this->assertStringNotContainsString($outside->code, $text, 'only the course\'s planned indicators are offered');
        $this->assertStringContainsString('ก. ตัวเลือก 1', $text, 'an exam question carries its option texts');

        $data = $this->asUser($this->teacher)->getJson($url)->assertOk()->json('data');
        $first = collect($data['questions'])->firstWhere('question_id', $questions[0]->id);
        $this->assertSame([$inCourse->code], array_map(fn (array $s) => $s['skill']['code'], $first['suggestions']));

        // Linked to a plan: the plan's indicators only, as for homework.
        $plan = LessonPlan::query()->where('course_id', $this->course->id)->sole();
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['lesson_plan_id' => $plan->id])->assertOk();
        $this->asUser($this->teacher)->getJson($url)->assertOk()
            ->assertJsonPath('data.indicator_source', 'lesson_plan')
            ->assertJsonPath('data.lesson_plan.id', $plan->id)
            ->assertJsonPath('data.course', null)
            ->assertJsonCount(1, 'data.plan_indicators')
            ->assertJsonPath('data.plan_indicators.0.code', $inPlan->code);
    }

    public function test_an_exam_course_without_indicators_is_refused_and_approval_suggests_on_its_own(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/indicator-suggestions")
            ->assertStatus(422)->assertJsonPath('code', 'lesson_plan_no_indicators');

        $this->courseIndicators();
        $this->approveExam($exam);
        $this->assertCount(1, $this->gemini->requests, 'approving the key suggested indicators for the unmapped questions');
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/indicator-suggestions")->assertOk()
            ->assertJsonPath('data.status', 'done')
            ->assertJsonPath('data.suggested_question_count', 2);
    }

    /**
     * Indicators of the course (course_indicators), of its unit and of its
     * lesson plan, plus one of the subject outside the course.
     *
     * @return list<Skill>
     */
    private function courseIndicators(): array
    {
        $subject = $this->course->subject_id;
        $make = fn (string $code, string $name) => Skill::factory()->create(['subject_id' => $subject, 'code' => $code, 'name' => $name, 'grade_level' => 5]);
        $inCourse = $make('ค 1.1 ป.5/1', 'บวกลบเศษส่วน');
        $inUnit = $make('ค 1.1 ป.5/2', 'คูณหารเศษส่วน');
        $inPlan = $make('ค 1.1 ป.5/3', 'ทศนิยม');
        $outside = $make('ค 2.1 ป.5/1', 'ความยาว');
        $this->course->indicators()->sync([$inCourse->id]);
        $unit = Unit::create(['course_id' => $this->course->id, 'position' => 1, 'title' => 'เศษส่วน']);
        $unit->indicators()->sync([$inUnit->id]);
        $plan = LessonPlan::create(['course_id' => $this->course->id, 'unit_id' => $unit->id, 'position' => 1, 'title' => 'ทศนิยม', 'objectives' => 'อ่านทศนิยมได้']);
        $plan->indicators()->sync([$inPlan->id]);

        return [$inCourse, $inUnit, $inPlan, $outside];
    }
}
