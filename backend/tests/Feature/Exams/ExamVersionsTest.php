<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamVersions;
use App\Models\Assignment;
use App\Models\ExamVersion;
use App\Models\Question;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.5: version ก is the original order, other versions shuffle
 * questions within each section and mcq options unless locked, from a
 * seed of SHA-256("{assignment_id}|{version_no}|{nonce}"), stored in
 * exam_versions, with keys derived from the master key.
 */
class ExamVersionsTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    /** @var array<string, mixed> */
    private array $mcq;

    /** @var array<string, mixed> */
    private array $tf;

    /** @var array<string, mixed> */
    private array $num;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeExamWorld();
        $this->exam = $this->createExam(['version_count' => 4]);
        $this->mcq = $this->addSection($this->exam, ['type' => 'mcq', 'option_count' => 5, 'question_count' => 8]);
        $this->tf = $this->addSection($this->exam, ['type' => 'true_false', 'question_count' => 6]);
        $this->num = $this->addSection($this->exam, ['type' => 'numeric', 'numeric' => ['digits' => 3], 'question_count' => 3]);
        $answers = [];
        foreach ($this->mcq['questions'] as $i => $q) {
            $answers[] = ['question_id' => $q['id'], 'accepted_options' => [($i % 5) + 1]];
        }
        foreach ($this->tf['questions'] as $i => $q) {
            $answers[] = ['question_id' => $q['id'], 'accepted_options' => [($i % 2) + 1]];
        }
        foreach ($this->num['questions'] as $i => $q) {
            $answers[] = ['question_id' => $q['id'], 'accepted_values' => [(string) ($i * 11)]];
        }
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$this->exam->id}/answer-key", ['answers' => $answers])->assertOk();
    }

    /** @return array<string, mixed> */
    private function versions(): array
    {
        return $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/versions")->assertOk()->json('data');
    }

    public function test_version_ko_is_the_original_order_and_the_others_shuffle_within_sections(): void
    {
        $data = $this->versions();
        $this->assertTrue($data['versions_ready']);
        $this->assertSame(['ก', 'ข', 'ค', 'ง'], array_column($data['versions'], 'label'));

        $original = [...array_column($this->mcq['questions'], 'id'), ...array_column($this->tf['questions'], 'id'), ...array_column($this->num['questions'], 'id')];
        $ko = $data['versions'][0];
        $this->assertSame($original, $ko['question_order']);
        $this->assertSame([], array_filter(array_column($ko['items'], 'option_order')));

        $mcqIds = array_column($this->mcq['questions'], 'id');
        $tfIds = array_column($this->tf['questions'], 'id');
        $numIds = array_column($this->num['questions'], 'id');
        $orders = [];
        foreach (array_slice($data['versions'], 1) as $version) {
            $order = $version['question_order'];
            // Sections keep their place; questions move only within their section.
            $this->assertEqualsCanonicalizing($mcqIds, array_slice($order, 0, 8));
            $this->assertEqualsCanonicalizing($tfIds, array_slice($order, 8, 6));
            $this->assertEqualsCanonicalizing($numIds, array_slice($order, 14, 3));
            $orders[] = $order;

            foreach ($version['items'] as $item) {
                if ($item['type'] === 'mcq') {
                    $this->assertEqualsCanonicalizing([1, 2, 3, 4, 5], $item['option_order']);
                } else {
                    $this->assertNull($item['option_order'], 'true_false and numeric keep their options');
                }
            }
        }
        $this->assertNotSame($original, $orders[0], '8 questions practically never shuffle back to the original order');
        $this->assertSame(range(1, 17), array_column($data['versions'][1]['items'], 'sheet_no'));
    }

    public function test_keys_of_every_version_come_from_the_master_key(): void
    {
        $questions = Question::query()->where('assignment_id', $this->exam->id)->get()->keyBy('id');
        foreach (array_slice($this->versions()['versions'], 1) as $version) {
            foreach ($version['items'] as $item) {
                $key = $questions[$item['question_id']]->answer_key;
                if ($item['type'] === 'numeric') {
                    $this->assertSame($key['accepted_values'], $item['accepted_values']);

                    continue;
                }
                $original = array_map(fn (int $displayed) => $item['option_order'] === null ? $displayed : $item['option_order'][$displayed - 1], $item['accepted_options']);
                $this->assertSame($key['accepted_options'], $original);
            }
        }
    }

    public function test_the_design_example_maps_the_key_through_the_permutation(): void
    {
        // §22.5: q1–q3, version ข = [q3, q1, q2], options of q1 = [2, 4, 1, 3], key of q1 = ค (3) -> ง at number 2.
        [$q1, $q2, $q3] = array_slice(array_column($this->mcq['questions'], 'id'), 0, 3);
        Question::query()->whereKey($q1)->update(['answer_key' => json_encode(['accepted_options' => [3]])]);
        $version = new ExamVersion(['version_no' => 2, 'question_order' => [$q3, $q1, $q2], 'option_orders' => [$q1 => [2, 4, 1, 3]]]);

        $items = ExamVersions::keyOf($version, Question::query()->whereKey([$q1, $q2, $q3])->with('section')->get()->keyBy('id'));

        $this->assertSame($q1, $items[1]['question_id']);
        $this->assertSame(2, $items[1]['sheet_no']);
        $this->assertSame([4], $items[1]['accepted_options']);
        $this->assertSame([2, 4, 1, 3], $items[1]['option_order']);
        $this->assertNull($items[0]['option_order']);
    }

    public function test_the_shuffle_is_deterministic_and_stored(): void
    {
        $first = $this->versions();
        $rows = ExamVersion::query()->where('assignment_id', $this->exam->id)->orderBy('version_no')->get();
        $this->assertSame(ExamVersions::seed($this->exam->id, 2, 0), $rows[1]->seed);
        $this->assertSame(substr(hash('sha256', "{$this->exam->id}|2|0"), 0, 16), $rows[1]->seed);

        // Rebuilding from nothing gives the same permutations.
        ExamVersion::query()->where('assignment_id', $this->exam->id)->delete();
        $this->assertSame($first['versions'], $this->versions()['versions']);

        // Locked options are never shuffled.
        $locked = $this->mcq['questions'][0]['id'];
        $this->asUser($this->teacher)->patchJson("/api/v1/questions/{$locked}", ['lock_options' => true])->assertOk();
        foreach (array_slice($this->versions()['versions'], 1) as $version) {
            $item = collect($version['items'])->firstWhere('question_id', $locked);
            $this->assertNull($item['option_order']);
        }
    }

    public function test_reshuffle_and_structure_changes_rebuild_the_versions_until_locked(): void
    {
        $before = $this->versions()['versions'];

        $after = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/versions/reshuffle")
            ->assertOk()->assertJsonPath('data.shuffle_nonce', 1)->json('data.versions');
        $this->assertSame($before[0]['question_order'], $after[0]['question_order']);
        $this->assertNotSame($before[1]['seed'], $after[1]['seed']);
        $this->assertNotSame(array_column($before, 'question_order'), array_column($after, 'question_order'));

        // A new question joins every version at once.
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sections/{$this->tf['id']}/questions", ['prompt_text' => 'ใหม่'])->assertCreated();
        $data = $this->versions();
        $this->assertTrue($data['versions_ready']);
        $this->assertCount(18, $data['versions'][3]['question_order']);

        // Fewer versions drop the extra rows.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$this->exam->id}", ['version_count' => 2])->assertOk();
        $this->assertSame([1, 2], ExamVersion::query()->where('assignment_id', $this->exam->id)->orderBy('version_no')->pluck('version_no')->all());

        // Once printed, the stored permutation is kept as it is.
        $this->exam->refresh()->forceFill(['structure_locked_at' => now()])->save();
        $stored = $this->versions()['versions'];
        Question::query()->whereKey($this->mcq['questions'][0]['id'])->update(['lock_options' => true]);
        $this->assertSame($stored, $this->versions()['versions']);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$this->exam->id}/versions/reshuffle")
            ->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
    }

    public function test_the_exam_payload_reports_the_answer_sheet_size(): void
    {
        $json = $this->examJson($this->exam);
        // 14 bubble rows and 3 digit blocks fit one page.
        $this->assertSame(['pages' => 1, 'overflow' => false], $json['sheet']);
        $this->assertTrue($json['versions_ready']);
        $this->assertTrue($json['key_complete']);
    }
}
