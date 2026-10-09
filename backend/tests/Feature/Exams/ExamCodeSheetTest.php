<?php

namespace Tests\Feature\Exams;

use App\Domain\Worksheets\QrSigner;
use App\Jobs\MergeWorksheetsJob;
use App\Jobs\RenderAnswerSheetsJob;
use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §22.19: the shared answer sheet on which the student fills in
 * their student ID (assignments.sheet_identity = code): the setting, the
 * sheet's capacity, printing one sheet for the class, the scan kit, and
 * POST /exam-sheets naming the student by the ID or by the teacher's pick.
 */
class ExamCodeSheetTest extends TestCase
{
    use ExamSheetTestHelpers;
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
        foreach ([1 => '66010001', 2 => '66010002', 3 => null] as $number => $code) {
            $student = $this->enrollStudent($this->classroom, $number, 'นักเรียน '.$number)['student'];
            $student->forceFill(['student_code' => $code])->save();
            $this->students[] = $student;
        }
    }

    /** An approved exam with the student-ID grid, its shared sheet printed once. */
    private function printedCodeExam(int $mcq = 4, int $digits = 8, int $versions = 1): Assignment
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => $digits, 'version_count' => $versions]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => $mcq]);
        $exam = $this->approveExam($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);

        return $exam->refresh();
    }

    /**
     * A shared-sheet page uploaded for $student: $code is what was filled in
     * the grid (null = nothing).
     *
     * @param  array<int, int|list<int>|string>  $marks
     * @param  array<string, mixed>  $extra
     */
    private function uploadShared(Assignment $exam, ?User $student, ?string $code, array $marks = [], int $page = 1, array $extra = [], ?int $version = null): TestResponse
    {
        if ($code !== null) {
            $marks[0] = $code;
        }
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->signCodeSheet($exam->id, $page, $this->layout($exam)->version),
            'scanned_at' => '2026-10-15T03:00:00Z',
            'blur_score' => 150.5,
            'student_id' => $student?->id,
            ...$this->reading($exam, $page, $marks, $version),
            ...$extra,
        ];

        return $this->asUser($this->teacher)->post('/api/v1/exam-sheets', [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json']);
    }

    public function test_an_exam_is_created_with_qr_sheets_unless_the_student_id_grid_is_asked_for(): void
    {
        $plain = $this->createExam();
        $this->assertSame('qr', $plain->sheet_identity);
        $this->assertNull($plain->student_code_digits);
        $this->assertFalse($plain->usesCodeSheets());

        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->assertTrue($exam->usesCodeSheets());
        $json = $this->examJson($exam);
        $this->assertSame('code', $json['exam']['sheet_identity']);
        $this->assertSame(8, $json['exam']['student_code_digits']);
    }

    public function test_the_grid_needs_its_number_of_digits_between_4_and_13(): void
    {
        $body = fn (array $fields) => $fields + [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'สอบ', 'kind' => 'exam', 'due_at' => '2026-10-15T02:00:00Z',
        ];
        $post = fn (array $fields) => $this->asUser($this->teacher)->postJson('/api/v1/assignments', $body($fields));

        $post(['sheet_identity' => 'code'])->assertStatus(422)
            ->assertJsonPath('errors.student_code_digits.0', 'กำหนดจำนวนหลักของเลขประจำตัวที่จะให้ฝน');
        $post(['sheet_identity' => 'code', 'student_code_digits' => 3])->assertStatus(422)
            ->assertJsonPath('errors.student_code_digits.0', 'เลขประจำตัวต้องมีอย่างน้อย 4 หลัก');
        $post(['sheet_identity' => 'code', 'student_code_digits' => 14])->assertStatus(422)
            ->assertJsonPath('errors.student_code_digits.0', 'เลขประจำตัวมีได้ไม่เกิน 13 หลัก');
        $post(['sheet_identity' => 'qr', 'student_code_digits' => 8])->assertStatus(422)
            ->assertJsonPath('errors.student_code_digits.0', 'จำนวนหลักใช้เมื่อเลือกฝนเลขประจำตัวเท่านั้น');
        // Homework has no answer sheet.
        $this->asUser($this->teacher)->postJson('/api/v1/assignments', [
            'classroom_id' => $this->classroom->id, 'course_id' => $this->course->id, 'title' => 'การบ้าน', 'sheet_identity' => 'code', 'student_code_digits' => 8,
        ])->assertStatus(422)->assertJsonValidationErrors(['sheet_identity']);
    }

    public function test_the_setting_changes_until_the_first_print_locks_the_structure(): void
    {
        $exam = $this->createExam();
        $patch = fn (array $fields) => $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", $fields);

        $patch(['sheet_identity' => 'code'])->assertStatus(422)->assertJsonValidationErrors(['student_code_digits']);
        $patch(['sheet_identity' => 'code', 'student_code_digits' => 10])->assertOk()
            ->assertJsonPath('data.sheet_identity', 'code')->assertJsonPath('data.student_code_digits', 10);
        $patch(['student_code_digits' => 5])->assertOk()->assertJsonPath('data.student_code_digits', 5);
        $patch(['student_code_digits' => null])->assertStatus(422)->assertJsonValidationErrors(['student_code_digits']);
        // Back to a sheet per student: the number of digits goes with it.
        $patch(['sheet_identity' => 'qr'])->assertOk()->assertJsonPath('data.student_code_digits', null);
        $patch(['sheet_identity' => 'code', 'student_code_digits' => 8])->assertOk();

        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'key_sheet'])->assertStatus(202);

        $patch(['sheet_identity' => 'qr'])->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
        $patch(['student_code_digits' => 9])->assertStatus(409)->assertJsonPath('code', 'exam_structure_locked');
        // Sending the values it already has is not a change.
        $patch(['sheet_identity' => 'code', 'student_code_digits' => 8, 'duration_minutes' => 45])->assertOk();
        $this->assertSame(8, $exam->refresh()->student_code_digits);
    }

    public function test_the_sheet_holds_sixty_rows_a_page_below_the_grid(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 61]);
        $this->assertSame(['pages' => 2, 'overflow' => false], $this->examJson($exam)['sheet']);

        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 60]);
        $this->assertSame(['pages' => 3, 'overflow' => true], $this->examJson($exam)['sheet']);
        $this->fillExam($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'key_sheet'])
            ->assertStatus(422)->assertJsonPath('code', 'exam_sheet_overflow');

        // The same 121 questions fit two pages of a sheet per student.
        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['sheet_identity' => 'qr'])->assertOk();
        $this->assertSame(['pages' => 2, 'overflow' => false], $this->examJson($exam)['sheet']);
    }

    public function test_printing_makes_one_shared_sheet_with_the_grid_on_every_page(): void
    {
        Bus::fake();
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 70]);
        $exam = $this->approveExam($exam);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet', 'student_ids' => [$this->students[0]->id]])
            ->assertStatus(422)
            ->assertJsonPath('errors.student_ids.0', 'กระดาษคำตอบแบบฝนเลขประจำตัวเป็นใบเดียวใช้ทั้งห้อง เลือกนักเรียนไม่ได้');
        $this->assertNull($exam->refresh()->structure_locked_at, 'a refused print locks nothing');

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);

        // One render job for the whole class, whatever its size.
        Bus::assertChained([
            fn (RenderAnswerSheetsJob $job) => $job->batch === 0 && $job->studentIds === [],
            MergeWorksheetsJob::class,
        ]);
        $pages = $this->layout($exam)->pages;
        $this->assertCount(2, $pages);
        foreach ($pages as $page) {
            $code = collect($page['regions'])->firstWhere('region_id', 'student_code');
            $this->assertSame(['digit_block', 0, 8], [$code['kind'], $code['sheet_no'], count($code['columns'])]);
        }
        $this->assertCount(60, collect($pages[0]['regions'])->where('kind', 'omr_row'));
        $this->assertCount(10, collect($pages[1]['regions'])->where('kind', 'omr_row'));
    }

    public function test_the_shared_sheet_prints_for_a_classroom_without_students(): void
    {
        foreach ($this->students as $student) {
            $this->classroom->students()->detach($student->id);
        }
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 6]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $exam = $this->approveExam($exam);

        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);
    }

    public function test_the_scan_kit_says_how_sheets_name_their_student_and_carries_the_ids(): void
    {
        $exam = $this->printedCodeExam();
        $kit = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/scan-kit")->assertOk()->json('data');

        $this->assertSame('code', $kit['sheet_identity']);
        $this->assertSame(8, $kit['student_code_digits']);
        $this->assertSame(['66010001', '66010002', null], array_column($kit['roster'], 'student_code'));
        $this->assertNotNull(collect($kit['layouts'][0]['regions'])->firstWhere('region_id', 'student_code'));

        $plain = $this->printedExam();
        $kit = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$plain->id}/scan-kit")->assertOk()->json('data');
        $this->assertSame('qr', $kit['sheet_identity']);
        $this->assertNull($kit['student_code_digits']);
    }

    public function test_a_page_is_filed_under_the_student_whose_id_is_filled_in(): void
    {
        $exam = $this->printedCodeExam();
        [$one, $two] = $this->students;

        $body = $this->uploadShared($exam, $two, '66010002', [1 => 1, 2 => 1, 3 => 2, 4 => 1])->assertStatus(201)->json();

        $this->assertSame($two->id, $body['student_id']);
        $this->assertSame('code', $body['identified_by']);
        $this->assertSame(3.0, (float) $body['score']);
        $this->assertSame(4.0, (float) $body['max_score']);
        $scan = Scan::query()->findOrFail($body['scan_id']);
        $this->assertSame($two->id, Submission::query()->findOrFail($scan->submission_id)->student_id);
        $read = ExamSheetRead::query()->findOrFail($scan->id);
        $this->assertSame(['code', '66010002'], [$read->identified_by, $read->student_code_read]);
        // The grid's fill is kept with the page, under sheet number 0, and is not an answer.
        $this->assertArrayHasKey('0', $read->digits_fill);
        $this->assertCount(4, $this->responses($exam, $two));
        $this->assertSame(0, Submission::query()->where('student_id', $one->id)->count());
    }

    public function test_the_server_reads_the_id_itself_and_refuses_another_students_page(): void
    {
        $exam = $this->printedCodeExam();
        [$one, $two, $noCode] = $this->students;

        // The phone says student 1, the grid says student 2.
        $this->uploadShared($exam, $one, '66010002')->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        // An ID that cannot be read, and a student without an ID, never match.
        $this->uploadShared($exam, $one, null)->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        $this->uploadShared($exam, $one, '6601000')->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        $this->uploadShared($exam, $noCode, null)->assertStatus(422)->assertJsonPath('code', 'student_code_mismatch');
        $this->uploadShared($exam, $two, '66010002', extra: ['student_source' => 'code'])->assertStatus(201);

        $this->assertSame(1, Scan::query()->count());
        $this->assertSame(1, Submission::query()->count(), 'a refused page leaves no empty submission');
    }

    public function test_the_teacher_can_name_the_student_when_the_id_does_not(): void
    {
        $exam = $this->printedCodeExam();
        [, , $noCode] = $this->students;

        $body = $this->uploadShared($exam, $noCode, null, [1 => 1], extra: ['student_source' => 'teacher'])->assertStatus(201)->json();
        $this->assertSame($noCode->id, $body['student_id']);
        $this->assertSame('teacher', $body['identified_by']);
        $read = ExamSheetRead::query()->findOrFail($body['scan_id']);
        $this->assertSame(['teacher', null], [$read->identified_by, $read->student_code_read]);

        // What the grid says is recorded even when the teacher overrules it.
        $body = $this->uploadShared($exam, $this->students[0], '66010002', extra: ['student_source' => 'teacher'])->assertStatus(201)->json();
        $this->assertSame('66010002', ExamSheetRead::query()->findOrFail($body['scan_id'])->student_code_read);
        $this->assertSame($this->students[0]->id, $body['student_id']);
    }

    public function test_a_shared_page_needs_a_student_of_the_classroom(): void
    {
        $exam = $this->printedCodeExam();
        $outsider = $this->enrollStudent($this->makeClassroom($this->teacher), 1, 'ห้องอื่น')['student'];

        $this->uploadShared($exam, null, '66010001')->assertStatus(422)
            ->assertJsonPath('errors.student_id.0', 'ไม่มีนักเรียนของกระดาษคำตอบนี้ (student_id)');
        $this->uploadShared($exam, $outsider, null, extra: ['student_source' => 'teacher'])->assertStatus(422)->assertJsonPath('code', 'student_unknown');
        $this->uploadShared($exam, $this->students[0], '66010001', extra: ['student_source' => 'guess'])->assertStatus(422)
            ->assertJsonValidationErrors(['student_source']);
        $this->assertSame(0, Scan::query()->count());
    }

    public function test_a_sheet_of_the_other_kind_is_refused(): void
    {
        $code = $this->printedCodeExam();
        $plain = $this->printedExam();

        // A sheet printed for a student, scanned for an exam that uses the shared sheet.
        $this->upload($code, $this->students[0], 1, $this->reading($code, 1, [0 => '66010001']))
            ->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        // A shared sheet scanned for an exam that prints a sheet per student.
        $this->uploadShared($plain, $this->students[0], null)->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        // The teacher's key sheet is still not a student's page.
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->signExamSheet($code->id, 0, 1, 1),
            'scanned_at' => '2026-10-15T03:00:00Z', 'blur_score' => 150.5, 'student_id' => $this->students[0]->id,
            ...$this->reading($code, 1, []),
        ];
        $this->asUser($this->teacher)->post('/api/v1/exam-sheets', [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', (string) file_get_contents(base_path('tests/fixtures/scans/page.webp'))),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $this->assertSame(0, Scan::query()->count());
    }

    public function test_both_pages_of_a_two_page_sheet_name_the_student_and_a_rescan_replaces_the_page(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 62]);
        $exam = $this->approveExam($exam);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);
        $student = $this->students[0];

        $first = $this->uploadShared($exam, $student, '66010001', [1 => 1])->assertStatus(201)->json();
        $second = $this->uploadShared($exam, $student, '66010001', [61 => 1, 62 => 2], page: 2)->assertStatus(201)->json();
        $this->assertSame($first['submission_id'], $second['submission_id']);
        $this->assertSame([1, 2], [$first['page_no'], $second['page_no']]);
        $this->assertSame(1.0, (float) $second['score']);
        $this->assertCount(62, $this->responses($exam, $student));

        // Page 1 again: the new scan takes its place, as for a sheet with the student's QR (§9.4).
        $again = $this->uploadShared($exam, $student, '66010001', [1 => 2])->assertStatus(201)->json();
        $this->assertSame(Scan::STATE_SUPERSEDED, Scan::query()->findOrFail($first['scan_id'])->state);
        $this->assertSame(Scan::STATE_ACTIVE, Scan::query()->findOrFail($again['scan_id'])->state);
        $this->assertSame(0.0, (float) $again['score']);
    }

    public function test_the_key_sheet_of_a_shared_sheet_exam_is_read_with_the_grid_left_empty(): void
    {
        $exam = $this->createExam(['sheet_identity' => 'code', 'student_code_digits' => 8]);
        $section = $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 3]);
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'key_sheet'])->assertStatus(202);

        $data = $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/key-sheet-read", [
            'qr' => app(QrSigner::class)->signExamSheet($exam->id, 0, 1, 1),
            ...$this->reading($exam, 1, [1 => 2, 2 => 4, 3 => 1]),
        ])->assertOk()->json('data');

        $this->assertSame([[2], [4], [1]], array_column($data['proposal'], 'accepted_options'));
        $this->assertCount(3, $section['questions']);
    }
}
