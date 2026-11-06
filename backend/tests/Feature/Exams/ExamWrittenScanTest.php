<?php

namespace Tests\Feature\Exams;

use App\Domain\Worksheets\QrSigner;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * DESIGN §22.20.5: the written pages of an exam come in through POST /scans
 * (the worksheet route), their answers are graded by Gemini against the
 * rubric and reviewed like homework, and the submission is complete and
 * publishable only with its bubble page too.
 */
class ExamWrittenScanTest extends TestCase
{
    use ExamSheetTestHelpers;
    use ExamTestHelpers;
    use RefreshDatabase;

    private Assignment $exam;

    private User $student;

    /** @var list<int> */
    private array $writtenIds;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['eduvision.qr_signing_key' => 'testing-qr-signing-key-not-a-secret']);
        $this->makeExamWorld();
        $exam = $this->createExam();
        $this->addSection($exam, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 4]);
        $open = $this->addSection($exam, ['type' => 'open', 'question_count' => 2, 'default_points' => 4]);
        $this->writtenIds = array_column($open['questions'], 'id');
        $this->approveExam($exam);
        $this->student = $this->enrollStudent($this->classroom, 1, 'นักเรียนหนึ่ง')['student'];
        $this->asUser($this->teacher)->postJson("/api/v1/exams/{$exam->id}/prints", ['kind' => 'answer_sheet'])->assertStatus(202);
        $this->exam = $exam->refresh();
    }

    /** The written page 2 as the phone would send it: both boxes cropped. */
    private function postWritten(?User $student = null, int $page = 2): TestResponse
    {
        $student ??= $this->student;
        $regions = [];
        $files = ['page' => $this->webp('page.webp')];
        foreach ($this->writtenIds as $id) {
            $regions[] = ['region_id' => "q{$id}", 'question_id' => $id, 'file' => "crop_q{$id}", 'ink_ratio' => 0.2];
            $files["crop_q{$id}"] = $this->webp('crop.webp');
        }
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->sign($this->exam->id, $student->id, $page, (int) $this->exam->current_layout_version),
            'scanned_at' => '2026-11-07T09:15:00+07:00',
            'blur_score' => 180.0,
            'regions' => $regions,
        ];

        return $this->asUser($this->teacher)->post('/api/v1/scans', ['meta' => json_encode($meta), ...$files], ['Accept' => 'application/json']);
    }

    private function webp(string $fixture): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($fixture, (string) file_get_contents(base_path('tests/fixtures/scans/'.$fixture)));
    }

    private function submission(): Submission
    {
        return Submission::query()->where('assignment_id', $this->exam->id)->where('student_id', $this->student->id)->firstOrFail();
    }

    /** Approves every written answer of the submission as graded. */
    private function reviewWritten(): void
    {
        foreach (Response::query()->where('submission_id', $this->submission()->id)->whereIn('question_id', $this->writtenIds)->get() as $response) {
            $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$response->id}", [
                'final_score' => (float) $response->ai_score,
                'final_understanding' => 'good',
            ])->assertOk();
        }
    }

    public function test_a_written_page_of_an_exam_is_filed_and_graded(): void
    {
        $this->postWritten()->assertSuccessful();

        $submission = $this->submission();
        $responses = Response::query()->where('submission_id', $submission->id)->orderBy('question_id')->get();
        $this->assertSame($this->writtenIds, $responses->pluck('question_id')->map(fn ($id) => (int) $id)->all());
        foreach ($responses as $response) {
            $this->assertContains($response->grading_state, [Response::STATE_SCORED, Response::STATE_MANUAL], 'the job graded it with the (fake) Gemini');
            $this->assertNull($response->exam_answer, 'a written answer is not a bubble answer');
        }
        $this->assertSame(1, $submission->scans()->count());
        $this->assertSame(2, $submission->scans()->value('page_no'));
    }

    public function test_the_bubble_page_of_an_exam_does_not_go_through_the_worksheet_route(): void
    {
        $this->postWritten(page: 1)->assertStatus(422)->assertJsonPath('code', 'page_mismatch');

        $this->assertSame(0, Submission::query()->where('assignment_id', $this->exam->id)->count());
    }

    public function test_a_written_page_alone_leaves_the_submission_waiting_for_the_bubble_page(): void
    {
        $this->postWritten()->assertSuccessful();
        $this->reviewWritten();

        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, $this->submission()->refresh()->status, 'page 1 is still missing');
    }

    public function test_with_both_pages_in_and_every_answer_reviewed_the_submission_is_reviewed(): void
    {
        $this->postWritten()->assertSuccessful();
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();
        $this->reviewWritten();

        $this->assertSame(Submission::STATUS_REVIEWED, $this->submission()->refresh()->status);
        $this->assertSame(6, Response::query()->where('submission_id', $this->submission()->id)->count(), '4 bubble + 2 written answers');
    }

    public function test_rescanning_the_bubble_page_leaves_the_reviewed_written_answers_alone(): void
    {
        $this->postWritten()->assertSuccessful();
        $this->reviewWritten();
        $before = Response::query()->where('submission_id', $this->submission()->id)->whereIn('question_id', $this->writtenIds)
            ->orderBy('question_id')->get(['id', 'final_score', 'reviewed_at'])->toArray();

        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 2, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();

        $after = Response::query()->where('submission_id', $this->submission()->id)->whereIn('question_id', $this->writtenIds)
            ->orderBy('question_id')->get(['id', 'final_score', 'reviewed_at'])->toArray();
        $this->assertSame($before, $after, 'the written responses and their review survived two bubble uploads');
    }

    public function test_the_class_publish_waits_for_the_written_page_and_then_publishes(): void
    {
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, $this->submission()->status, 'bubbles only: the written page is missing');

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/publish")->assertOk();
        $this->assertNull($this->submission()->refresh()->published_at, 'not published without its written page');

        $this->postWritten()->assertSuccessful();
        $this->assertNull($this->submission()->refresh()->published_at, 'written answers not reviewed yet');
        $this->reviewWritten();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/publish")->assertOk();

        $published = $this->submission()->refresh();
        $this->assertNotNull($published->published_at);
        $this->assertEquals(
            round((float) Response::query()->where('submission_id', $published->id)->sum('final_score'), 2),
            round((float) $published->total_score, 2),
            'the total counts the bubble and the written answers',
        );
    }

    public function test_publishing_names_the_missing_written_page(): void
    {
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$this->submission()->id}/publish")
            ->assertStatus(409)->assertJsonPath('code', 'submission_not_reviewed')
            ->assertJsonPath('errors.missing_pages', ['2'])
            ->assertJsonPath('errors.missing_questions', ['5', '6']);
    }

    public function test_the_sheet_status_counts_the_written_page_and_what_is_still_to_review(): void
    {
        $this->postWritten()->assertSuccessful();

        $status = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/sheet-status")->assertOk()->json();
        $row = $status['data'][0];
        $this->assertSame([2], $row['pages_received']);
        $this->assertSame(2, $row['page_count']);
        $this->assertSame([['page_no' => 2, 'kind' => 'written']], array_map(fn (array $p) => ['page_no' => $p['page_no'], 'kind' => $p['kind']], $row['pages']));
        $this->assertFalse($row['needs_version'], 'a written page has no version to pick');
        $this->assertSame(2, $row['written_pending']);
        $this->assertSame(2, $status['summary']['written_pending']);
        $this->assertSame(0, $status['summary']['scanned'], 'page 1 is still missing');

        $this->reviewWritten();
        $status = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/sheet-status")->assertOk()->json();
        $this->assertSame(0, $status['data'][0]['written_pending']);
        $this->assertSame(0, $status['summary']['written_pending']);
        $this->assertEquals(12.0, $status['summary']['max_score'], '4 bubble points and 2 written answers of 4');
    }

    public function test_the_scan_kit_carries_the_written_pages_but_no_key_for_them(): void
    {
        $kit = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/scan-kit")->assertOk()->json('data');

        $this->assertSame(2, $kit['page_count']);
        $this->assertSame(['exam', 'written'], array_column($kit['layouts'], 'sheet'));
        $this->assertSame([1, 2, 3, 4], array_column($kit['versions'][0]['key'], 'sheet_no'), 'the key has the bubble answers only');
    }

    public function test_a_written_exam_can_requeue_what_waited_for_a_gemini_key_and_a_bubble_exam_cannot(): void
    {
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/requeue-missing-key")
            ->assertOk()->assertJsonPath('data.requeued', 0);

        $bubbles = $this->createExam(['title' => 'สอบฝนล้วน']);
        $this->addSection($bubbles, ['type' => 'mcq', 'option_count' => 4, 'question_count' => 2]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$bubbles->id}/requeue-missing-key")
            ->assertStatus(422)->assertJsonPath('code', 'exam_kind_unsupported');
    }

    public function test_the_exam_endpoints_cope_with_written_answers(): void
    {
        $this->postWritten()->assertSuccessful();
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();
        $this->reviewWritten();
        $teacher = $this->asUser($this->teacher);

        $teacher->getJson("/api/v1/exams/{$this->exam->id}")->assertOk();
        $teacher->getJson("/api/v1/exams/{$this->exam->id}/option-analysis")->assertOk();
        $teacher->getJson("/api/v1/exams/{$this->exam->id}/sheet-status")->assertOk();
        $teacher->getJson("/api/v1/assignments/{$this->exam->id}/analytics")->assertOk();
        $teacher->postJson("/api/v1/assignments/{$this->exam->id}/regrade/estimate")->assertOk();
        $written = Response::query()->whereIn('question_id', $this->writtenIds)->orderBy('id')->get(['id', 'ai_score', 'final_score', 'reviewed_at'])->toArray();
        $teacher->postJson("/api/v1/assignments/{$this->exam->id}/regrade")->assertSuccessful();
        $this->assertSame(
            $written,
            Response::query()->whereIn('question_id', $this->writtenIds)->orderBy('id')->get(['id', 'ai_score', 'final_score', 'reviewed_at'])->toArray(),
            'the regrade by code left the written answers as they were',
        );

        $teacher->postJson("/api/v1/assignments/{$this->exam->id}/publish")->assertOk();
        $this->asUser($this->student)->getJson('/api/v1/student/results')->assertOk();
        $this->asUser($this->student)->getJson("/api/v1/student/results/{$this->submission()->id}")->assertOk();
    }

    private function publishWholeSheet(): void
    {
        $this->postWritten()->assertSuccessful();
        $this->upload($this->exam, $this->student, 1, $this->reading($this->exam, 1, [1 => 1, 2 => 2, 3 => 3, 4 => 4]))->assertSuccessful();
        $this->reviewWritten();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->exam->id}/publish")->assertOk();
        $this->assertNotNull($this->submission()->refresh()->published_at);
    }

    public function test_a_written_page_scanned_again_after_publishing_is_graded_again_when_confirmed(): void
    {
        $this->publishWholeSheet();
        $oldScan = Response::query()->where('submission_id', $this->submission()->id)->whereIn('question_id', $this->writtenIds)->value('scan_id');

        $pending = $this->postWritten()->assertStatus(202)->assertJsonPath('state', 'pending_confirm')->json('scan_id');
        $this->assertNotSame((int) $oldScan, (int) $pending);
        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pending}/confirm-replace")->assertOk()->assertJsonPath('state', 'active');

        $written = Response::query()->where('submission_id', $this->submission()->id)->whereIn('question_id', $this->writtenIds)->get();
        $this->assertCount(2, $written);
        foreach ($written as $response) {
            $this->assertSame((int) $pending, (int) $response->scan_id, 'the answer now comes from the new page');
            $this->assertContains($response->grading_state, [Response::STATE_QUEUED, Response::STATE_SCORED, Response::STATE_MANUAL]);
            $this->assertNull($response->reviewed_at, 'the new reading waits for the teacher');
        }
        $submission = $this->submission()->refresh();
        $this->assertNull($submission->published_at, 'it is reopened until the teacher reviews it again');
        $this->assertNotSame(Submission::STATUS_REVIEWED, $submission->status);
    }

    public function test_the_option_analysis_leaves_the_written_questions_out(): void
    {
        $this->publishWholeSheet();

        $questions = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$this->exam->id}/option-analysis")->assertOk()->json('data.questions');

        $ids = array_column($questions, 'question_id');
        $this->assertSame([], array_intersect($this->writtenIds, $ids), 'a written question has no options to count');
        $this->assertCount(4, $questions);
    }

    public function test_a_written_page_of_an_older_layout_version_is_refused(): void
    {
        $current = $this->exam->currentLayout();
        $this->exam->layouts()->create(['version' => 2, 'pages' => $current->pages]);
        $oldVersion = (int) $current->version;
        $this->exam->forceFill(['current_layout_version' => 2])->save();
        $this->exam->refresh();

        $regions = [];
        $files = ['page' => $this->webp('page.webp')];
        foreach ($this->writtenIds as $id) {
            $regions[] = ['region_id' => "q{$id}", 'question_id' => $id, 'file' => "crop_q{$id}", 'ink_ratio' => 0.2];
            $files["crop_q{$id}"] = $this->webp('crop.webp');
        }
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->sign($this->exam->id, $this->student->id, 2, $oldVersion),
            'scanned_at' => '2026-11-07T09:15:00+07:00',
            'blur_score' => 180.0,
            'regions' => $regions,
        ];

        $this->asUser($this->teacher)->post('/api/v1/scans', ['meta' => json_encode($meta), ...$files], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonPath('code', 'layout_unknown');
        $this->assertSame(0, Submission::query()->where('assignment_id', $this->exam->id)->count());
    }
}
