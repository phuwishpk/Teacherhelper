<?php

namespace Tests\Feature\Api;

use App\Models\Assignment;
use App\Models\Question;
use App\Models\Skill;
use App\Models\Subject;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DESIGN §8.3 / §9.3 questions: answer_key validated per type, positions,
 * skills, rubric status and the layout going stale after an edit.
 */
class QuestionsTest extends TestCase
{
    use RefreshDatabase;

    private function assignment(array $attributes = []): array
    {
        $teacher = $this->makeTeacher();
        $subject = Subject::factory()->create();
        $assignment = Assignment::factory()
            ->for_classroom($this->makeClassroom($teacher))
            ->create(['subject_id' => $subject->id, ...$attributes]);

        return [$teacher, $assignment, $subject];
    }

    /** @return array<string, array{array<string, mixed>, array<string, mixed>|null, string}> */
    public static function validQuestions(): array
    {
        return [
            'mcq' => [
                ['type' => 'mcq', 'prompt_text' => 'ข้อใดถูก', 'max_points' => 1, 'answer_key' => ['correct' => 'c']],
                ['correct' => 'C'],
                'not_needed',
            ],
            'short numeric' => [
                ['type' => 'short', 'prompt_text' => '12.5 + 7.5', 'max_points' => 2, 'is_numeric' => true,
                    'answer_key' => ['accepted' => [' 20 ', '20.0'], 'numeric' => ['value' => 20, 'abs_tol' => 0.01], 'extra' => 'dropped']],
                ['accepted' => ['20', '20.0'], 'numeric' => ['value' => 20, 'abs_tol' => 0.01]],
                'not_needed',
            ],
            'short text exact' => [
                ['type' => 'short', 'prompt_text' => 'สะกดคำว่า', 'max_points' => 1, 'match_mode' => 'exact',
                    'answer_key' => ['accepted' => ['กรุงเทพมหานคร']]],
                ['accepted' => ['กรุงเทพมหานคร']],
                'not_needed',
            ],
            'show_work' => [
                ['type' => 'show_work', 'prompt_text' => '3x + 5 = 20', 'max_points' => 5, 'answer_lines' => 4, 'is_numeric' => true,
                    'answer_key' => ['final' => ['accepted' => ['x = 5'], 'numeric' => ['value' => 5]], 'reference_steps' => ['3x = 15', '', 'x = 5']]],
                ['final' => ['accepted' => ['x = 5'], 'numeric' => ['value' => 5, 'abs_tol' => 0]], 'reference_steps' => ['3x = 15', 'x = 5']],
                'draft',
            ],
            'open' => [
                ['type' => 'open', 'prompt_text' => 'อธิบาย', 'max_points' => 4, 'answer_lines' => 5, 'answer_key' => null],
                null,
                'draft',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $storedKey
     */
    #[DataProvider('validQuestions')]
    public function test_each_type_is_stored_with_a_normalised_answer_key(array $payload, ?array $storedKey, string $rubricStatus): void
    {
        [$teacher, $assignment] = $this->assignment();

        $response = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.position', 1)
            ->assertJsonPath('data.type', $payload['type'])
            ->assertJsonPath('data.rubric_status', $rubricStatus)
            ->assertJsonPath('data.skills', [])
            ->assertJsonPath('data.rubric_criteria', []);

        $this->assertEquals($storedKey, $response->json('data.answer_key'));
        $this->assertIsFloat($response->json('data.max_points') + 0.0);
        $this->assertSame((float) $payload['max_points'], (float) $response->json('data.max_points'));
    }

    /** @return array<string, array{array<string, mixed>, list<string>}> */
    public static function invalidQuestions(): array
    {
        return [
            'mcq without key' => [['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1], ['answer_key']],
            'mcq option E' => [['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['correct' => 'E']], ['answer_key.correct']],
            'mcq numeric box' => [['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'is_numeric' => true, 'answer_key' => ['correct' => 'A']], ['is_numeric']],
            'short empty accepted' => [['type' => 'short', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['accepted' => []]], ['answer_key.accepted']],
            'short blank answer' => [['type' => 'short', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['accepted' => ['  ']]], ['answer_key.accepted.0']],
            'short numeric without value' => [['type' => 'short', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['accepted' => ['1'], 'numeric' => ['abs_tol' => 1]]], ['answer_key.numeric.value']],
            'short negative tolerance' => [['type' => 'short', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['accepted' => ['1'], 'numeric' => ['value' => 1, 'abs_tol' => -1]]], ['answer_key.numeric.abs_tol']],
            'show_work without final' => [['type' => 'show_work', 'prompt_text' => 'x', 'max_points' => 5, 'answer_lines' => 3, 'answer_key' => ['reference_steps' => []]], ['answer_key.final']],
            'show_work without lines' => [['type' => 'show_work', 'prompt_text' => 'x', 'max_points' => 5, 'answer_key' => ['final' => ['accepted' => ['5']]]], ['answer_lines']],
            'open with key' => [['type' => 'open', 'prompt_text' => 'x', 'max_points' => 4, 'answer_lines' => 3, 'answer_key' => ['accepted' => ['x']]], ['answer_key']],
            'open too many lines' => [['type' => 'open', 'prompt_text' => 'x', 'max_points' => 4, 'answer_lines' => 16], ['answer_lines']],
            'unknown type' => [['type' => 'essay', 'prompt_text' => 'x', 'max_points' => 1], ['type']],
            'zero points' => [['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 0, 'answer_key' => ['correct' => 'A']], ['max_points']],
            'three decimals' => [['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1.125, 'answer_key' => ['correct' => 'A']], ['max_points']],
            'no prompt' => [['type' => 'mcq', 'max_points' => 1, 'answer_key' => ['correct' => 'A']], ['prompt_text']],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $fields
     */
    #[DataProvider('invalidQuestions')]
    public function test_invalid_questions_are_rejected(array $payload, array $fields): void
    {
        [$teacher, $assignment] = $this->assignment();

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", $payload)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonValidationErrors($fields);
        $this->assertSame(0, Question::query()->count());
    }

    public function test_skills_must_belong_to_the_assignment_subject_and_be_visible_to_the_school(): void
    {
        [$teacher, $assignment, $subject] = $this->assignment();
        $curriculum = Skill::factory()->create(['subject_id' => $subject->id]);
        $ownSubSkill = Skill::factory()->create(['subject_id' => $subject->id, 'school_id' => $teacher->school_id, 'parent_id' => $curriculum->id]);
        $otherSubject = Skill::factory()->create();
        $otherSchool = Skill::factory()->create(['subject_id' => $subject->id, 'school_id' => $this->makeSchool()->id]);
        $base = ['type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['correct' => 'A']];

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [...$base, 'skill_ids' => [$curriculum->id, $ownSubSkill->id]])
            ->assertStatus(201)
            ->assertJsonCount(2, 'data.skills');

        foreach ([$otherSubject, $otherSchool] as $skill) {
            $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [...$base, 'skill_ids' => [$skill->id]])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['skill_ids.0']);
        }
    }

    public function test_positions_stay_a_gap_free_sequence(): void
    {
        [$teacher, $assignment] = $this->assignment();
        $base = ['type' => 'mcq', 'max_points' => 1, 'answer_key' => ['correct' => 'A']];
        $ids = [];
        foreach (['หนึ่ง', 'สอง', 'สาม'] as $text) {
            $ids[$text] = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [...$base, 'prompt_text' => $text])->json('data.id');
        }

        // Insert at position 1 pushes the others down.
        $ids['ศูนย์'] = $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [...$base, 'prompt_text' => 'ศูนย์', 'position' => 1])
            ->assertStatus(201)->assertJsonPath('data.position', 1)->json('data.id');
        $this->assertOrder($assignment, ['ศูนย์', 'หนึ่ง', 'สอง', 'สาม']);

        // Move the last question to position 2.
        $this->asUser($teacher)->patchJson("/api/v1/questions/{$ids['สาม']}", ['position' => 2])->assertOk()->assertJsonPath('data.position', 2);
        $this->assertOrder($assignment, ['ศูนย์', 'สาม', 'หนึ่ง', 'สอง']);

        // Delete closes the gap.
        $this->asUser($teacher)->deleteJson("/api/v1/questions/{$ids['ศูนย์']}")->assertNoContent();
        $this->assertOrder($assignment, ['สาม', 'หนึ่ง', 'สอง']);

        $this->asUser($teacher)->getJson("/api/v1/assignments/{$assignment->id}")
            ->assertOk()
            ->assertJsonPath('data.questions.0.prompt_text', 'สาม')
            ->assertJsonPath('data.questions.0.position', 1)
            ->assertJsonPath('data.questions_count', 3);
    }

    /** @param list<string> $texts */
    private function assertOrder(Assignment $assignment, array $texts): void
    {
        $rows = Question::query()->where('assignment_id', $assignment->id)->orderBy('position')->get(['position', 'prompt_text']);
        $this->assertSame($texts, $rows->pluck('prompt_text')->all());
        $this->assertSame(range(1, count($texts)), $rows->pluck('position')->all());
    }

    public function test_patch_changes_only_the_fields_sent(): void
    {
        [$teacher, $assignment] = $this->assignment();
        $question = Question::factory()->short()->create(['assignment_id' => $assignment->id]);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['prompt_text' => 'โจทย์ใหม่'])
            ->assertOk()
            ->assertJsonPath('data.prompt_text', 'โจทย์ใหม่')
            ->assertJsonPath('data.type', 'short')
            ->assertJsonPath('data.is_numeric', true)
            ->assertJsonPath('data.answer_key.accepted', ['20']);
    }

    public function test_changing_the_type_needs_a_matching_key_and_resets_the_rubric(): void
    {
        [$teacher, $assignment] = $this->assignment();
        $question = Question::factory()->open()->create(['assignment_id' => $assignment->id]);
        $question->rubricCriteria()->create(['position' => 1, 'description' => 'แก่น', 'points' => 4, 'is_core' => true, 'source' => 'teacher']);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['type' => 'mcq'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['answer_key']);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['type' => 'mcq', 'answer_key' => ['correct' => 'D']])
            ->assertOk()
            ->assertJsonPath('data.rubric_status', 'not_needed')
            ->assertJsonPath('data.answer_lines', null)
            ->assertJsonPath('data.rubric_criteria', []);
    }

    public function test_changing_max_points_reopens_an_approved_rubric(): void
    {
        [$teacher, $assignment] = $this->assignment();
        $question = Question::factory()->open()->create(['assignment_id' => $assignment->id]);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['max_points' => 6])
            ->assertOk()
            ->assertJsonPath('data.rubric_status', 'draft');
    }

    public function test_an_edit_that_changes_the_page_sends_a_ready_assignment_back_to_draft(): void
    {
        [$teacher, $assignment] = $this->assignment(['status' => 'ready', 'current_layout_version' => 1]);
        $question = Question::factory()->short()->create(['assignment_id' => $assignment->id]);

        // The answer key is not printed: the layout stays valid.
        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['answer_key' => ['accepted' => ['20', 'ยี่สิบ']]])->assertOk();
        $this->assertSame('ready', $assignment->refresh()->status);

        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['is_numeric' => false])->assertOk();
        $this->assertSame('draft', $assignment->refresh()->status);
        $this->assertSame(1, $assignment->current_layout_version, 'printed sheets keep their version');
    }

    public function test_adding_a_question_to_a_ready_assignment_sends_it_back_to_draft(): void
    {
        [$teacher, $assignment] = $this->assignment(['status' => 'ready', 'current_layout_version' => 1]);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [
            'type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['correct' => 'A'],
        ])->assertStatus(201);

        $this->assertSame('draft', $assignment->refresh()->status);
    }

    public function test_a_closed_assignment_cannot_be_edited(): void
    {
        [$teacher, $assignment] = $this->assignment(['status' => 'closed']);
        $question = Question::factory()->create(['assignment_id' => $assignment->id]);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$assignment->id}/questions", [
            'type' => 'mcq', 'prompt_text' => 'x', 'max_points' => 1, 'answer_key' => ['correct' => 'A'],
        ])->assertStatus(409)->assertJsonPath('code', 'assignment_closed');
        $this->asUser($teacher)->patchJson("/api/v1/questions/{$question->id}", ['prompt_text' => 'y'])->assertStatus(409);
        $this->asUser($teacher)->deleteJson("/api/v1/questions/{$question->id}")->assertStatus(409);
    }

    public function test_another_teachers_questions_are_not_found(): void
    {
        [$teacher] = $this->assignment();
        [, $foreignAssignment] = $this->assignment();
        $foreign = Question::factory()->create(['assignment_id' => $foreignAssignment->id]);

        $this->asUser($teacher)->postJson("/api/v1/assignments/{$foreignAssignment->id}/questions", ['type' => 'mcq'])->assertNotFound();
        $this->asUser($teacher)->patchJson("/api/v1/questions/{$foreign->id}", ['prompt_text' => 'x'])->assertNotFound();
        $this->asUser($teacher)->deleteJson("/api/v1/questions/{$foreign->id}")->assertNotFound();
        $this->assertDatabaseHas('questions', ['id' => $foreign->id, 'prompt_text' => $foreign->prompt_text]);
    }
}
