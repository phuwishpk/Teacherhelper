<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamScanKit;
use App\Domain\Worksheets\QrSigner;
use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Layout;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §22.9–§22.11, §22.15: the scan kit, answer-sheet pages scored by
 * code on the server (POST /exam-sheets), the running summary
 * (GET /exams/{id}/sheet-status) and the teacher's key sheet
 * (POST /exams/{id}/key-sheet-read).
 */
class ExamSheetScanTest extends TestCase
{
    use ExamTestHelpers;
    use RefreshDatabase;

    /** @var list<User> */
    private array $students = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
        for ($n = 1; $n <= 3; $n++) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, 'นักเรียน '.$n)['student'];
        }
    }

    /**
     * An approved app exam with $mcq 4-option rows and $numeric 2-digit
     * blocks, printed once as answer sheets (layout version 1, locked).
     */
    private function printedExam(int $mcq = 4, int $numeric = 0, int $versions = 1): Assignment
    {
        $exam = $this->createExam(['version_count' => $versions]);
        while ($mcq > 0) {
            $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => min(100, $mcq)]);
            $mcq -= 100;
        }
        if ($numeric > 0) {
            $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_decimal' => true], 'question_count' => $numeric]);
        }
        $exam = $this->approveExam($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);

        return $exam->refresh();
    }

    private function layout(Assignment $exam): Layout
    {
        return $exam->refresh()->currentLayout() ?? $this->fail('no layout');
    }

    /**
     * Readings of one layout page: every bubble 0.02 except the marks.
     * $marks: sheet_no => displayed position (int), several (list) or a
     * number (string); $version: the version bubble to mark.
     *
     * @param  array<int, int|list<int>|string>  $marks
     * @return array{version_fill: array<string, float>|null, rows: array<string, array<string, float>>, digits: array<string, mixed>}
     */
    private function reading(Assignment $exam, int $page, array $marks, ?int $version = null): array
    {
        $layoutPage = $this->layout($exam)->pages[$page - 1];
        $versionFill = null;
        $rows = [];
        $digits = [];
        foreach ($layoutPage['regions'] as $region) {
            if ($region['kind'] === 'version_bubbles') {
                foreach ($region['bubbles'] as $b) {
                    $versionFill[(string) $b['value']] = $b['value'] === $version ? 0.92 : 0.02;
                }
            } elseif ($region['kind'] === 'omr_row') {
                $mark = (array) ($marks[$region['sheet_no']] ?? []);
                foreach ($region['bubbles'] as $b) {
                    $rows[(string) $region['sheet_no']][(string) $b['value']] = in_array($b['value'], $mark, true) ? 0.9 : 0.02;
                }
            } else {
                $text = (string) ($marks[$region['sheet_no']] ?? '');
                $columns = [];
                foreach ($region['columns'] as $i => $column) {
                    $fill = [];
                    foreach ($column['bubbles'] as $b) {
                        $fill[(string) $b['value']] = ($text[$i] ?? null) === (string) $b['value'] ? 0.9 : 0.02;
                    }
                    $columns[] = $fill;
                }
                $digits[(string) $region['sheet_no']] = ['sign' => $region['sign'] === null ? null : 0.02, 'columns' => $columns];
            }
        }

        return ['version_fill' => $versionFill, 'rows' => $rows, 'digits' => $digits];
    }

    /**
     * @param  array<string, mixed>  $reading
     * @param  array<string, mixed>  $extra
     */
    private function upload(Assignment $exam, User $student, int $page, array $reading, array $extra = []): TestResponse
    {
        $qr = app(QrSigner::class)->signExamSheet($exam->id, $student->id, $page, $this->layout($exam)->version);
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => $qr,
            'scanned_at' => '2026-10-15T03:00:00Z',
            'blur_score' => 150.5,
            ...$reading,
            ...$extra,
        ];

        return $this->asUser($this->teacher)->post('/api/v1/exam-sheets', [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json']);
    }

    /** @return array<int, Response> by original question position */
    private function responses(Assignment $exam, User $student): array
    {
        $submission = Submission::query()->where('assignment_id', $exam->id)->where('student_id', $student->id)->firstOrFail();
        $out = [];
        foreach (Response::query()->where('submission_id', $submission->id)->with('question')->get() as $r) {
            $out[$r->question->position] = $r;
        }
        ksort($out);

        return $out;
    }

    public function test_scan_kit_needs_an_approved_key_of_an_app_exam(): void
    {
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/scan-kit")
            ->assertStatus(409)->assertJsonPath('code', 'answer_key_not_approved');

        $manual = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 20]);
        $this->asUser($this->teacher)->getJson("/api/v1/exams/{$manual->id}/scan-kit")
            ->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
    }

    public function test_scan_kit_carries_layouts_per_version_keys_in_displayed_positions_and_the_roster(): void
    {
        $exam = $this->printedExam(mcq: 6, numeric: 1, versions: 2);
        $kit = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/scan-kit")->assertOk()->json('data');

        $this->assertSame(1, $kit['layout_version']);
        $this->assertSame(1, $kit['page_count']);
        $this->assertSame(2, $kit['version_count']);
        $this->assertCount(1, $kit['layouts']);
        $this->assertSame('exam', $kit['layouts'][0]['sheet']);
        $this->assertSame([1, 2, 3], array_column($kit['roster'], 'student_number'));
        $this->assertSame(64, strlen($kit['kit_hash']));
        $this->assertSame(['ข'], [$kit['versions'][1]['label']]);

        // Every mcq key is ก (original 1): version ข shows it where its permutation put option 1.
        $versions = $exam->examVersions()->orderBy('version_no')->get();
        foreach ($kit['versions'][1]['key'] as $item) {
            $this->assertSame($versions[1]->question_order[$item['sheet_no'] - 1], $item['question_id']);
            if ($item['type'] === 'numeric') {
                $this->assertSame(['1'], $item['accepted_values']);
                $this->assertArrayNotHasKey('accepted_options', $item);

                continue;
            }
            $order = $versions[1]->option_orders[$item['question_id']] ?? [1, 2, 3, 4];
            $this->assertSame([array_search(1, $order, true) + 1], $item['accepted_options']);
        }

        // Editing the key changes the hash, so the phone knows its copy is stale.
        $question = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->firstOrFail();
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", [
            'answers' => [['question_id' => $question->id, 'accepted_options' => [2]]],
        ])->assertOk();
        $again = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/scan-kit")->assertOk()->json('data');
        $this->assertNotSame($kit['kit_hash'], $again['kit_hash']);
    }

    public function test_a_page_is_scored_by_code_and_clear_answers_are_reviewed_at_once(): void
    {
        $exam = $this->printedExam(mcq: 4, numeric: 1);
        // Key: every mcq original 1 (ก), the number "1". Sheet numbers = positions (one version).
        $reading = $this->reading($exam, 1, [1 => 1, 2 => 3, 3 => [1, 2], 5 => '1'], null);
        $reading['rows']['4']['2'] = 0.3; // an unclear bubble in an otherwise blank row

        $body = $this->upload($exam, $this->students[0], 1, $reading, ['device_score' => 3])
            ->assertCreated()
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('page_no', 1)
            ->assertJsonPath('page_count', 1)
            ->assertJsonPath('version_no', 1)
            ->assertJsonPath('needs_version', false)
            ->json();
        $this->assertEqualsWithDelta(2.0, $body['score'], 1e-9);
        $this->assertEqualsWithDelta(5.0, $body['max_score'], 1e-9);
        $this->assertSame([['sheet_no' => 3, 'reason' => 'double_mark'], ['sheet_no' => 4, 'reason' => 'ambiguous_mark']], $body['doubts']);

        $r = $this->responses($exam, $this->students[0]);
        $this->assertSame(1.0, $r[1]->ai_score);
        $this->assertSame(1.0, $r[1]->final_score);
        $this->assertNotNull($r[1]->reviewed_at);
        $this->assertNull($r[1]->reviewed_by);
        $this->assertSame('confident', $r[1]->priority_band);
        $this->assertSame(['sheet_no' => 1, 'version_no' => 1, 'selected' => [1], 'value' => null, 'doubts' => []], $r[1]->exam_answer);
        $this->assertSame(0.0, $r[2]->ai_score);
        $this->assertSame('check', $r[3]->priority_band);
        $this->assertNull($r[3]->reviewed_at);
        $this->assertSame(['double_mark'], $r[3]->exam_answer['doubts']);
        $this->assertSame('check', $r[4]->priority_band);
        $this->assertSame('1', $r[5]->exam_answer['value']);
        $this->assertSame(1.0, $r[5]->ai_score);
        $this->assertSame(ScoreEvent::ACTION_AI_SCORED, ScoreEvent::query()->where('response_id', $r[1]->id)->value('action'));

        $submission = Submission::query()->where('assignment_id', $exam->id)->where('student_id', $this->students[0]->id)->firstOrFail();
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, $submission->status);
        $read = ExamSheetRead::query()->findOrFail($body['scan_id']);
        $this->assertSame(3.0, $read->device_score); // the phone's score is kept, the server's counts
        Storage::disk('local')->assertExists(Scan::query()->findOrFail($body['scan_id'])->page_image_path);
    }

    public function test_a_clean_sheet_is_reviewed_and_a_retry_replays_the_same_answer(): void
    {
        $exam = $this->printedExam(mcq: 2);
        $reading = $this->reading($exam, 1, [1 => 1, 2 => 1]);
        $qr = app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 1);
        $meta = ['client_scan_id' => (string) Str::uuid(), 'qr' => $qr, 'scanned_at' => '2026-10-15T03:00:00Z', 'blur_score' => 120, ...$reading];
        $send = fn () => $this->asUser($this->teacher)->post('/api/v1/exam-sheets', [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json']);

        $first = $send()->assertCreated()->json();
        $second = $send()->assertOk()->json();
        $this->assertSame($first, $second);
        $this->assertSame(1, Scan::query()->count());
        $this->assertSame(Submission::STATUS_REVIEWED, Submission::query()->firstOrFail()->status);
    }

    public function test_a_rescan_before_publishing_replaces_the_page(): void
    {
        $exam = $this->printedExam(mcq: 2);
        $first = $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, [1 => 2, 2 => 1]))->assertCreated()->json('scan_id');
        $second = $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, [1 => 1, 2 => 1]))->assertCreated()->json('scan_id');

        $this->assertSame(Scan::STATE_SUPERSEDED, Scan::query()->findOrFail($first)->state);
        $r = $this->responses($exam, $this->students[0]);
        $this->assertSame($second, $r[1]->scan_id);
        $this->assertSame(1.0, $r[1]->ai_score);
        $this->assertSame(1, ScoreEvent::query()->where('response_id', $r[1]->id)->where('action', ScoreEvent::ACTION_RESCAN)->count());
    }

    public function test_a_rescan_of_a_published_sheet_waits_for_confirm_replace(): void
    {
        $exam = $this->printedExam(mcq: 2);
        $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, [1 => 2, 2 => 1]))->assertCreated();
        Submission::query()->update(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);

        $pending = $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, [1 => 1, 2 => 1]))
            ->assertStatus(202)
            ->assertJsonPath('state', 'pending_confirm')
            ->json();
        $this->assertEqualsWithDelta(2.0, $pending['score'], 1e-9); // what it would score
        $this->assertSame(0.0, $this->responses($exam, $this->students[0])[1]->ai_score); // published answers untouched

        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pending['scan_id']}/confirm-replace")
            ->assertOk()->assertJsonPath('state', 'active');
        $this->assertSame(1.0, $this->responses($exam, $this->students[0])[1]->ai_score);
        $this->assertSame(Submission::STATUS_REVIEWED, Submission::query()->firstOrFail()->status);
    }

    public function test_versions_come_from_the_bubbles_and_page_two_waits_for_page_one(): void
    {
        $exam = $this->printedExam(mcq: 120, versions: 2);
        $this->assertSame(2, $this->layout($exam)->pageCount());
        $keys = ExamScanKit::keys($exam);
        // Bubble the right answer of version ข everywhere.
        $right = fn (int $page) => collect($keys[2])
            ->filter(fn (array $i) => ($page === 1) === ($i['sheet_no'] <= 100))
            ->mapWithKeys(fn (array $i) => [$i['sheet_no'] => $i['accepted_options'][0]])
            ->all();

        $two = $this->upload($exam, $this->students[1], 2, $this->reading($exam, 2, $right(2)))
            ->assertCreated()
            ->assertJsonPath('version_no', null)
            ->assertJsonPath('needs_version', true)
            ->assertJsonPath('doubts.0.reason', 'version_waiting_page_one')
            ->json();
        $this->assertSame([], $this->responses($exam, $this->students[1]));
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, Submission::query()->firstOrFail()->status);

        $this->upload($exam, $this->students[1], 1, $this->reading($exam, 1, $right(1), version: 2))
            ->assertCreated()
            ->assertJsonPath('version_no', 2);
        $responses = $this->responses($exam, $this->students[1]);
        $this->assertCount(120, $responses);
        $this->assertSame(120.0, array_sum(array_map(fn (Response $r) => $r->ai_score, $responses)));
        $this->assertSame(2, ExamSheetRead::query()->findOrFail($two['scan_id'])->version_no);
        $this->assertSame('page_one', ExamSheetRead::query()->findOrFail($two['scan_id'])->version_source);
        // selected is stored in ORIGINAL positions: every key is ก (1).
        $this->assertSame([1], $responses[1]->exam_answer['selected']);
        $this->assertSame(Submission::STATUS_REVIEWED, Submission::query()->firstOrFail()->status);

        $status = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertOk()->json();
        $this->assertSame(['scanned' => 1, 'total' => 3, 'missing_numbers' => [1, 3], 'page_count' => 2, 'max_score' => 120], $status['summary']);
        $this->assertSame([1, 2], $status['data'][1]['pages_received']);
        $this->assertSame(2, $status['data'][1]['version_no']);
        $this->assertEquals(120, $status['data'][1]['score']);
        $this->assertSame('missing', $status['data'][0]['status']);
    }

    public function test_an_unreadable_version_leaves_the_page_for_the_teacher(): void
    {
        $exam = $this->printedExam(mcq: 2, versions: 2);
        $reading = $this->reading($exam, 1, [1 => 1]);
        $this->upload($exam, $this->students[0], 1, $reading)
            ->assertCreated()
            ->assertJsonPath('needs_version', true)
            ->assertJsonPath('score', null)
            ->assertJsonPath('doubts.0.reason', 'version_unknown');
        $this->assertSame([], $this->responses($exam, $this->students[0]));
        $status = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertOk()->json('data.0');
        $this->assertTrue($status['needs_version']);
        $this->assertSame('needs_review', $status['status']);
    }

    public function test_uploads_that_do_not_fit_are_refused(): void
    {
        $exam = $this->printedExam(mcq: 2);
        $reading = $this->reading($exam, 1, [1 => 1]);

        // The teacher's key sheet, a worksheet QR, a forged signature.
        $key = app(QrSigner::class)->signExamSheet($exam->id, 0, 1, 1);
        $this->upload($exam, $this->students[0], 1, $reading, ['qr' => $key])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $worksheet = app(QrSigner::class)->sign($exam->id, $this->students[0]->id, 1, 1);
        $this->upload($exam, $this->students[0], 1, $reading, ['qr' => $worksheet])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $forged = substr(app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 1), 0, -8).'AAAAAAAA';
        $this->upload($exam, $this->students[0], 1, $reading, ['qr' => $forged])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');

        // Another layout version, a page that does not exist, another page's readings.
        $old = app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 7);
        $this->upload($exam, $this->students[0], 1, $reading, ['qr' => $old])->assertStatus(422)->assertJsonPath('code', 'layout_unknown');
        $page9 = app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 9, 1);
        $this->upload($exam, $this->students[0], 1, $reading, ['qr' => $page9])->assertStatus(422)->assertJsonPath('code', 'page_mismatch');
        $this->upload($exam, $this->students[0], 1, ['rows' => ['1' => ['1' => 0.9]]])->assertStatus(422)->assertJsonPath('code', 'page_mismatch');
        $this->upload($exam, $this->students[0], 1, ['rows' => ['1' => ['1' => 1.5]]])->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        // A student of another classroom.
        $stranger = $this->enrollStudent($this->makeClassroom($this->teacher), 1)['student'];
        $this->upload($exam, $stranger, 1, $reading)->assertStatus(422)->assertJsonPath('code', 'student_unknown');

        // Answer sheets never go through POST /scans.
        $this->asUser($this->teacher)->post('/api/v1/scans', [
            'meta' => json_encode(['client_scan_id' => (string) Str::uuid(), 'qr' => app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 1), 'scanned_at' => '2026-10-15T03:00:00Z', 'blur_score' => 100, 'regions' => [['region_id' => 's1', 'question_id' => 1, 'file' => 'c1']]]),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');

        $this->assertSame(0, Scan::query()->count());
    }

    public function test_another_teacher_cannot_upload_to_the_exam(): void
    {
        $exam = $this->printedExam(mcq: 2);
        $other = $this->makeTeacher($this->teacher->school);
        $qr = app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 1);
        $this->asUser($other)->post('/api/v1/exam-sheets', [
            'meta' => json_encode(['client_scan_id' => (string) Str::uuid(), 'qr' => $qr, 'scanned_at' => '2026-10-15T03:00:00Z', 'blur_score' => 100, ...$this->reading($exam, 1, [])]),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json'])->assertStatus(403);
        $this->asUser($other)->getJson("/api/v1/exams/{$exam->id}/scan-kit")->assertNotFound();
        $this->asUser($other)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertNotFound();
    }

    public function test_the_key_sheet_proposes_the_master_key_without_saving_it(): void
    {
        $exam = $this->createExam(['version_count' => 2]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $this->addSection($exam, ['type' => 'true_false', 'question_count' => 1]);
        $this->addSection($exam, ['type' => 'numeric', 'numeric' => ['digits' => 2, 'allow_decimal' => true], 'question_count' => 1]);
        // Before approving: the key sheet prints and reads.
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'key_sheet'])->assertStatus(202);
        $exam->refresh();
        $keys = ExamScanKit::keys($exam)[2];
        $bySheet = [];
        foreach ($keys as $sheetNo => $item) {
            $bySheet[$item['question_id']] = [$sheetNo, $item['option_order'] ?? null];
        }
        $questions = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        [$q1, $q2, $q3, $tf, $num] = $questions->all();
        // Version ข: q1 displayed 2, q2 displayed 1 and 3 (two accepted), q3 blank, true/false both, number "12".
        $marks = [
            $bySheet[$q1->id][0] => 2,
            $bySheet[$q2->id][0] => [1, 3],
            $bySheet[$tf->id][0] => [1, 2],
            $bySheet[$num->id][0] => '12',
        ];
        $reading = $this->reading($exam, 1, $marks, version: 2);
        $qr = app(QrSigner::class)->signExamSheet($exam->id, 0, 1, 1);

        $data = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/key-sheet-read", ['qr' => $qr, ...$reading])
            ->assertOk()->json('data');
        $this->assertSame(2, $data['version_no']);
        $proposal = collect($data['proposal'])->keyBy('question_id');
        $original = fn (int $qid, array $displayed) => ExamScanKit::original($displayed, $bySheet[$qid][1]);
        $this->assertSame($original($q1->id, [2]), $proposal[$q1->id]['accepted_options']);
        $this->assertFalse($proposal[$q1->id]['doubtful']);
        $this->assertTrue($proposal[$q1->id]['differs']);
        $this->assertSame($original($q2->id, [1, 3]), $proposal[$q2->id]['accepted_options']);
        $this->assertSame([], $proposal[$q3->id]['accepted_options']);
        $this->assertTrue($proposal[$q3->id]['doubtful']);
        $this->assertSame([], $proposal[$tf->id]['accepted_options']);
        $this->assertTrue($proposal[$tf->id]['doubtful']);
        $this->assertSame(['12'], $proposal[$num->id]['accepted_values']);
        $this->assertNull($q1->refresh()->answer_key); // nothing saved

        // No version bubble: 422 version_unknown; a student's sheet QR: 422 qr_invalid.
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/key-sheet-read", ['qr' => $qr, ...$this->reading($exam, 1, $marks)])
            ->assertStatus(422)->assertJsonPath('code', 'version_unknown');
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/key-sheet-read", ['qr' => app(QrSigner::class)->signExamSheet($exam->id, $this->students[0]->id, 1, 1), ...$reading])
            ->assertStatus(422)->assertJsonPath('code', 'qr_invalid');

        // After unlocking the structure the old key sheet is layout_unknown.
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/unlock-structure")->assertOk();
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/key-sheet-read", ['qr' => $qr, ...$reading])
            ->assertStatus(422)->assertJsonPath('code', 'layout_unknown');
    }
}
