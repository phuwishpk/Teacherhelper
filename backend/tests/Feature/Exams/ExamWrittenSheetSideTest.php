<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamSheetLayout;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\ExamVersion;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.20.3 and the step-1 guard of §22.20.9: written questions are
 * not bubble rows, not shuffled and not in the key by sheet number; until
 * step 2 gives them pages, the answer sheet and scan-kit of an exam that
 * has them answer 422 exam_written_not_ready.
 */
class ExamWrittenSheetSideTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    /** @var list<int> */
    private array $writtenIds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
        $this->exam = $this->createExam(['version_count' => 3]);
        $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 6]);
        $open = $this->addSection($this->exam, ['type' => 'open', 'question_count' => 2]);
        $this->writtenIds = array_column($open['questions'], 'id');
        $this->exam = $this->approveExam($this->exam);
    }

    public function test_versions_do_not_shuffle_or_key_written_questions(): void
    {
        $data = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/versions")->assertOk()->json('data');

        $this->assertCount(3, $data['versions']);
        foreach ($data['versions'] as $version) {
            $this->assertSame($this->writtenIds, array_slice($version['question_order'], -2), "version {$version['version_no']} keeps the written order");
            $this->assertSame([1, 2, 3, 4, 5, 6], array_column($version['items'], 'sheet_no'));
            $this->assertSame([], array_intersect($this->writtenIds, array_column($version['items'], 'question_id')));
        }
        $this->assertCount(3, ExamVersion::query()->where('assignment_id', $this->exam->id)->get());
    }

    public function test_bubble_layout_ignores_written_questions(): void
    {
        $items = ExamSheetLayout::items($this->exam);

        $this->assertCount(6, $items);
        foreach ($items as $item) {
            $this->assertContains($item->type, ExamSection::TYPES);
        }
    }

    public function test_sheet_prints_and_scan_kit_are_refused_for_written_exams(): void
    {
        Queue::fake();
        $url = "/api/v1/exams/{$this->exam->id}/prints";

        $this->asUser($this->teacher)->postJson($url, ['kind' => 'answer_sheet'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_not_ready');
        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/scan-kit")
            ->assertStatus(422)->assertJsonPath('code', 'exam_written_not_ready')
            ->assertJsonPath('message', 'ยังพิมพ์กระดาษคำตอบหรือเตรียมสแกนข้อสอบที่มีข้อเขียนไม่ได้ (กำลังพัฒนา)');
        $this->assertNull($this->exam->refresh()->structure_locked_at, 'a refused print does not lock the structure');

        $this->asUser($this->teacher)->postJson($url, ['kind' => 'exam_booklet', 'version_no' => 1])->assertStatus(202);
        $this->asUser($this->teacher)->postJson($url, ['kind' => 'key_sheet'])->assertStatus(202);
    }

    public function test_the_copier_skips_written_questions_and_the_library_hides_them(): void
    {
        $target = $this->createExam(['title' => 'สอบย่อย']);
        $section = $this->addSection($target, ['type' => 'open']);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => $this->writtenIds])
            ->assertCreated()
            ->assertJsonPath('data.created', 0)
            ->assertJsonPath('data.skipped.0.reason', 'written_not_copyable');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$target->id}/copy-questions", ['question_ids' => $this->writtenIds, 'section_id' => $section['id']])
            ->assertCreated()->assertJsonPath('data.created', 0);
        $this->assertSame(0, Question::query()->where('section_id', $section['id'])->count());
        $this->assertSame(1, ExamSection::query()->where('assignment_id', $target->id)->count(), 'no section was created for them');

        $listed = $this->asUser($this->teacher)->getJson('/api/v1/teacher/exam-questions')->assertOk()->json('data');
        $this->assertSame([], array_intersect($this->writtenIds, array_column($listed, 'id')));
        $this->assertCount(6, $listed);
    }

    public function test_an_essay_only_exam_has_no_key_sheet_and_stays_unlocked(): void
    {
        Queue::fake();
        $essay = $this->createExam(['title' => 'สอบเขียนล้วน']);
        $this->addSection($essay, ['type' => 'open', 'question_count' => 2]);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$essay->id}/prints", ['kind' => 'key_sheet'])
            ->assertStatus(422)->assertJsonPath('code', 'assignment_empty')
            ->assertJsonPath('message', 'ข้อสอบนี้ไม่มีข้อฝน จึงไม่มีกระดาษเฉลย (เฉลยของข้อเขียนคือ rubric)');

        $this->assertNull($essay->refresh()->structure_locked_at, 'a refused print does not lock the structure');
        $this->assertSame(0, $essay->worksheetPrints()->count());
    }
}
