<?php

namespace Tests\Feature\Gradebook;

use App\Models\Assignment;
use App\Models\GradebookItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** DESIGN §23.2 / §23.3: categories, templates, cutoffs and the category of assignments. */
class GradebookSettingsTest extends TestCase
{
    use GradebookWorld;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeGradebookWorld();
    }

    public function test_templates_are_listed(): void
    {
        $data = $this->asUser($this->teacher)->getJson('/api/v1/gradebook/templates')->assertOk()->json('data');

        $this->assertSame(['collect_final', 'hw_mid_final_affective'], array_column($data, 'key'));
        $this->assertSame('คะแนนเก็บ 70 : ปลายภาค 30', $data[0]['name']);
        $this->assertSame([['name' => 'คะแนนเก็บ', 'weight' => 70, 'is_homework_default' => true], ['name' => 'ปลายภาค', 'weight' => 30, 'is_homework_default' => false]], $data[0]['categories']);
    }

    public function test_a_template_sets_up_the_course_once_and_homework_gets_the_default_category(): void
    {
        $homework = $this->appAssignment('การบ้านเก่า', 10, null);
        $exam = $this->appAssignment('ข้อสอบเก่า', 10, null, ['kind' => Assignment::KIND_EXAM, 'grading_method' => Assignment::GRADING_APP]);

        $settings = $this->asUser($this->teacher)->getJson("/api/v1/courses/{$this->course->id}/gradebook/settings")->assertOk()->json('data');
        $this->assertFalse($settings['configured']);
        $this->assertSame([80, 75, 70, 65, 60, 55, 50], $settings['cutoffs']);

        $settings = $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['template' => 'hw_mid_final_affective'])
            ->assertOk()->json('data');

        $this->assertTrue($settings['configured']);
        $this->assertSame('hw_mid_final_affective', $settings['template']);
        $this->assertSame(['การบ้าน', 'กลางภาค', 'ปลายภาค', 'จิตพิสัย'], array_column($settings['categories'], 'name'));
        $this->assertSame([30, 20, 30, 20], array_map('intval', array_column($settings['categories'], 'weight')));
        $this->assertSame([1, 0, 0, 0], array_column($settings['categories'], 'item_count'));
        $this->assertSame(1, $settings['uncategorised_count']); // the exam waits for the teacher's pick
        $this->assertSame($settings['categories'][0]['id'], $homework->refresh()->gradebook_category_id);
        $this->assertNull($exam->refresh()->gradebook_category_id);

        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['template' => 'collect_final'])
            ->assertStatus(409)->assertJsonPath('code', 'gradebook_configured');
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['template' => 'nope'])
            ->assertStatus(422);
    }

    public function test_weights_must_sum_to_exactly_100(): void
    {
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['categories' => [
            ['name' => 'เก็บ', 'weight' => 70.5],
            ['name' => 'สอบ', 'weight' => 29.49],
        ]])->assertStatus(422)->assertJsonPath('code', 'weights_not_100')->assertJsonStructure(['errors' => ['categories']]);

        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['categories' => [
            ['name' => 'เก็บ', 'weight' => 70.51],
            ['name' => 'สอบ', 'weight' => 29.49, 'drop_lowest' => 2],
        ]])->assertOk()->assertJsonPath('data.categories.1.drop_lowest', 2);
    }

    public function test_invalid_category_sets_are_refused(): void
    {
        $url = "/api/v1/courses/{$this->course->id}/gradebook/categories";
        $this->asUser($this->teacher)->putJson($url, ['categories' => [['name' => 'ก', 'weight' => 50], ['name' => 'ก ', 'weight' => 50]]])
            ->assertStatus(422)->assertJsonValidationErrors(['categories.1.name']);
        $this->asUser($this->teacher)->putJson($url, ['categories' => [
            ['name' => 'ก', 'weight' => 50, 'is_homework_default' => true], ['name' => 'ข', 'weight' => 50, 'is_homework_default' => true],
        ]])->assertStatus(422)->assertJsonValidationErrors(['categories']);
        $this->asUser($this->teacher)->putJson($url, ['categories' => [['name' => 'ก', 'weight' => 100, 'drop_lowest' => 6]]])
            ->assertStatus(422)->assertJsonValidationErrors(['categories.0.drop_lowest']);
        $this->asUser($this->teacher)->putJson($url, ['categories' => [['name' => 'ก', 'weight' => 0], ['name' => 'ข', 'weight' => 100]]])
            ->assertStatus(422)->assertJsonValidationErrors(['categories.0.weight']);
        $this->asUser($this->teacher)->putJson($url, ['categories' => [['id' => 999999, 'name' => 'ก', 'weight' => 100]]])
            ->assertStatus(422)->assertJsonValidationErrors(['categories.0.id']);
        $this->asUser($this->teacher)->putJson($url, [])->assertStatus(422);
    }

    public function test_replacing_the_set_keeps_renames_reorders_and_deletes_categories(): void
    {
        $cats = $this->useTemplate();
        $homework = $this->appAssignment('การบ้าน 1', 10, $cats['การบ้าน']);
        $itemId = $this->item('การแต่งกาย', 10, $cats['จิตพิสัย']);

        $settings = $this->asUser($this->teacher)->putJson("/api/v1/courses/{$this->course->id}/gradebook/categories", ['categories' => [
            ['id' => $cats['ปลายภาค']->id, 'name' => 'สอบปลายภาค', 'weight' => 40],
            ['id' => $cats['การบ้าน']->id, 'name' => 'การบ้าน', 'weight' => 40, 'drop_lowest' => 1, 'is_homework_default' => true],
            ['name' => 'โครงงาน', 'weight' => 20],
        ]])->assertOk()->json('data');

        $this->assertSame(['สอบปลายภาค', 'การบ้าน', 'โครงงาน'], array_column($settings['categories'], 'name'));
        $this->assertSame([1, 2, 3], array_column($settings['categories'], 'position'));
        $this->assertSame($cats['ปลายภาค']->id, $settings['categories'][0]['id']);
        $this->assertSame($cats['การบ้าน']->id, $homework->refresh()->gradebook_category_id);
        // จิตพิสัย was deleted: its item is now "ยังไม่ระบุหมวด".
        $this->assertNull(GradebookItem::query()->findOrFail($itemId)->category_id);
        $this->assertSame(1, $settings['uncategorised_count']);
    }

    public function test_cutoffs_can_be_edited_and_reset(): void
    {
        $url = "/api/v1/courses/{$this->course->id}/gradebook/cutoffs";
        $this->asUser($this->teacher)->putJson($url, ['cutoffs' => [85, 80, 75, 70, 65, 60, 55]])
            ->assertOk()->assertJsonPath('data.cutoffs', [85, 80, 75, 70, 65, 60, 55])->assertJsonPath('data.default_cutoffs', [80, 75, 70, 65, 60, 55, 50]);
        $this->asUser($this->teacher)->putJson($url, ['cutoffs' => [80, 81, 70, 65, 60, 55, 50]])->assertStatus(422)->assertJsonValidationErrors(['cutoffs']);
        $this->asUser($this->teacher)->putJson($url, ['cutoffs' => [80, 75, 70]])->assertStatus(422)->assertJsonValidationErrors(['cutoffs']);
        $this->asUser($this->teacher)->putJson($url, [])->assertStatus(422)->assertJsonValidationErrors(['cutoffs']);
        $this->asUser($this->teacher)->putJson($url, ['cutoffs' => null])->assertOk()->assertJsonPath('data.cutoffs', [80, 75, 70, 65, 60, 55, 50]);
        $this->assertNull($this->course->refresh()->grade_cutoffs);
    }

    public function test_new_homework_gets_the_default_and_a_new_exam_must_pick_a_category_of_its_course(): void
    {
        $cats = $this->useTemplate();
        $other = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ค15102']);
        $this->asUser($this->teacher)->putJson("/api/v1/courses/{$other->id}/gradebook/categories", ['template' => 'collect_final'])->assertOk();
        $otherCats = $other->gradebookCategories()->get()->keyBy('name');
        $base = ['classroom_id' => $this->classroom->id, 'course_id' => $this->course->id];

        $hw = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['title' => 'การบ้าน'])->assertCreated();
        $hw->assertJsonPath('data.gradebook_category_id', $cats['การบ้าน']->id)->assertJsonPath('data.excluded_from_grade', false);

        $practice = $this->asUser($this->teacher)->postJson('/api/v1/assignments', $base + ['title' => 'งานฝึก', 'excluded_from_grade' => true])->assertCreated();
        $practice->assertJsonPath('data.excluded_from_grade', true);

        $exam = $base + ['title' => 'สอบ', 'kind' => 'exam', 'due_at' => now()->addWeek()->toIso8601String()];
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $exam)->assertStatus(422)->assertJsonValidationErrors(['gradebook_category_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $exam + ['gradebook_category_id' => $otherCats['ปลายภาค']->id])
            ->assertStatus(422)->assertJsonValidationErrors(['gradebook_category_id']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', $exam + ['gradebook_category_id' => $cats['กลางภาค']->id])
            ->assertCreated()->assertJsonPath('data.gradebook_category_id', $cats['กลางภาค']->id);

        // An exam of a course without a gradebook needs no category.
        $plain = $this->makeCourse($this->teacher, [$this->classroom], ['code' => 'ค15103']);
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', ['course_id' => $plain->id] + $exam)
            ->assertCreated()->assertJsonPath('data.gradebook_category_id', null);

        // PATCH: pick another category of the course, or move to another course (back to its default).
        $id = $hw->json('data.id');
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['gradebook_category_id' => $cats['จิตพิสัย']->id, 'excluded_from_grade' => true])
            ->assertOk()->assertJsonPath('data.gradebook_category_id', $cats['จิตพิสัย']->id)->assertJsonPath('data.excluded_from_grade', true);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['gradebook_category_id' => $otherCats['ปลายภาค']->id])
            ->assertStatus(422)->assertJsonValidationErrors(['gradebook_category_id']);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['course_id' => $other->id])
            ->assertOk()->assertJsonPath('data.gradebook_category_id', $otherCats['คะแนนเก็บ']->id);
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$id}", ['gradebook_category_id' => null])
            ->assertOk()->assertJsonPath('data.gradebook_category_id', null);
    }
}
