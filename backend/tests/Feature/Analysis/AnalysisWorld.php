<?php

namespace Tests\Feature\Analysis;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\Mastery;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\User;

/**
 * A classroom of three students bound to a course with five indicators
 * (DESIGN §20.5): A has mastery of all five, B of one, C of none.
 *
 *   A: i1 0.9 (n 3), i2 0.8 (n 1), i3 0.6 (n 4), i4 0.3 (n 2), i5 0.76 (n 2)
 *   B: i4 0.5 (n 1)
 *
 * i4 has an approved practice item, i3 has none.
 */
trait AnalysisWorld
{
    protected User $teacher;

    protected Classroom $room;

    protected Course $course;

    /** @var array<string, Skill> */
    protected array $s = [];

    /** @var array<string, User> */
    protected array $students = [];

    protected function makeAnalysisWorld(): void
    {
        $this->teacher = $this->makeTeacher();
        $math = Subject::query()->updateOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        foreach (['i1' => 'บวกเลข', 'i2' => 'ลบเลข', 'i3' => 'คูณเลข', 'i4' => 'เศษส่วน', 'i5' => 'ทศนิยม'] as $k => $name) {
            $this->s[$k] = Skill::factory()->create(['subject_id' => $math->id, 'grade_level' => 5, 'code' => 'ค 1.1 ป.5/'.substr($k, 1), 'name' => $name]);
        }
        $this->room = $this->makeClassroom($this->teacher, ['grade_level' => 5, 'name' => 'ป.5/1']);
        foreach (['A' => 'เด็กชายสมชาย ใจดี', 'B' => 'เด็กหญิงสมศรี รักเรียน', 'C' => 'เด็กชายสมปอง มาเรียน'] as $n => $name) {
            $this->students[$n] = $this->enrollStudent($this->room, count($this->students) + 17, $name)['student'];
        }
        $this->course = $this->makeCourse($this->teacher, [$this->room], ['subject_id' => $math->id]);
        $this->course->indicators()->sync(array_map(fn (Skill $s) => $s->id, $this->s));

        $this->mastery('A', 'i1', 0.9, 3);
        $this->mastery('A', 'i2', 0.8, 1);
        $this->mastery('A', 'i3', 0.6, 4);
        $this->mastery('A', 'i4', 0.3, 2);
        $this->mastery('A', 'i5', 0.76, 2);
        $this->mastery('B', 'i4', 0.5, 1);

        PracticeItem::create([
            'school_id' => $this->teacher->school_id, 'skill_id' => $this->s['i4']->id, 'answer_type' => 'numeric',
            'prompt_text' => '1/2 + 1/2 = ?', 'options' => null,
            'answer_key' => ['accepted' => ['1'], 'numeric' => ['value' => 1, 'abs_tol' => 0]],
            'explanation' => 'ได้ 1', 'status' => PracticeItem::STATUS_APPROVED, 'source' => 'teacher',
            'approved_by' => $this->teacher->id, 'approved_at' => now(),
        ]);
    }

    protected function mastery(string $student, string $skill, float $value, int $nObs): void
    {
        Mastery::query()->updateOrInsert(
            ['student_id' => $this->students[$student]->id, 'skill_id' => $this->s[$skill]->id],
            ['value' => $value, 'n_obs' => $nObs, 'created_at' => now(), 'updated_at' => now()],
        );
    }

    protected function fake(): FakeGeminiClient
    {
        $client = app(GeminiClient::class);
        $this->assertInstanceOf(FakeGeminiClient::class, $client);

        return $client;
    }
}
