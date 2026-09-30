<?php

namespace Tests\Feature\Exams;

use App\Domain\Exams\ExamScanKit;
use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Grading\ClassRegrade;
use App\Jobs\RescoreExamJob;
use App\Models\AiCall;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * DESIGN §22.3, §22.11, §22.12, §22.15: the review of scanned answer sheets
 * by code: picking a page's version, the teacher's reading of a doubtful
 * mark (resolve), "ตรวจใหม่ทั้งห้อง" of an exam, "ประกาศผลทั้งห้อง" and
 * what the student sees. Gemini is never called (fake client, no key).
 */
class ExamSheetReviewTest extends TestCase
{
    use ExamSheetTestHelpers;
    use ExamTestHelpers;
    use RefreshDatabase;

    /** @var list<User> */
    private array $students = [];

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
        for ($n = 1; $n <= 3; $n++) {
            $this->students[] = $this->enrollStudent($this->classroom, $n, 'นักเรียน '.$n)['student'];
        }
    }

    protected function tearDown(): void
    {
        // No path of this file may reach Gemini (§22.1: exams are never graded by AI).
        $this->assertSame([], $this->gemini->requests);
        $this->assertSame(0, AiCall::query()->count());
        parent::tearDown();
    }

    private function submission(Assignment $exam, User $student): Submission
    {
        return Submission::query()->where('assignment_id', $exam->id)->where('student_id', $student->id)->firstOrFail();
    }

    public function test_the_teacher_picks_the_version_of_a_page_and_it_is_scored_at_once(): void
    {
        $exam = $this->printedExam(mcq: 120, versions: 2);
        $keys = ExamScanKit::keys($exam);
        $right = fn (int $page) => collect($keys[2])
            ->filter(fn (array $i) => ($page === 1) === ($i['sheet_no'] <= 100))
            ->mapWithKeys(fn (array $i) => [$i['sheet_no'] => $i['accepted_options'][0]])
            ->all();
        $student = $this->students[0];

        // Both version bubbles filled: unknown; page 2 waits for page 1.
        $reading = $this->reading($exam, 1, $right(1));
        $reading['version_fill'] = ['1' => 0.9, '2' => 0.9];
        $one = $this->upload($exam, $student, 1, $reading)->assertCreated()->assertJsonPath('needs_version', true)->json('scan_id');
        $two = $this->upload($exam, $student, 2, $this->reading($exam, 2, $right(2)))->assertCreated()->assertJsonPath('needs_version', true)->json('scan_id');
        $this->assertSame([], $this->responses($exam, $student));

        $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$one}/version", ['version_no' => 3])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$one}/version", [])
            ->assertStatus(422)->assertJsonPath('errors.version_no.0', 'เลือกชุดข้อสอบ (version_no)');

        $body = $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$one}/version", ['version_no' => 2])
            ->assertOk()
            ->assertJsonPath('version_no', 2)
            ->assertJsonPath('needs_version', false)
            ->json();
        $this->assertEqualsWithDelta(100.0, $body['score'], 1e-9);
        $this->assertSame(ExamSheetRead::SOURCE_TEACHER, ExamSheetRead::query()->findOrFail($one)->version_source);
        // Page 2 took page 1's version: every answer of both pages is right.
        $responses = $this->responses($exam, $student);
        $this->assertCount(120, $responses);
        $this->assertSame(120.0, array_sum(array_map(fn (Response $r) => $r->ai_score, $responses)));
        $this->assertSame(2, ExamSheetRead::query()->findOrFail($two)->version_no);
        $this->assertSame(Submission::STATUS_REVIEWED, $this->submission($exam, $student)->status);

        // Picking the wrong version rewrites the answers against that version's key.
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$one}/version", ['version_no' => 1])->assertOk();
        $this->assertLessThan(120.0, array_sum(array_map(fn (Response $r) => $r->ai_score, $this->responses($exam, $student))));

        // A replaced page cannot be given a version.
        $three = $this->upload($exam, $student, 1, $this->reading($exam, 1, $right(1), version: 2))->assertCreated()->json('scan_id');
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$one}/version", ['version_no' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'scan_superseded');
        $this->assertSame(Scan::STATE_ACTIVE, Scan::query()->findOrFail($three)->state);
    }

    public function test_a_published_page_cannot_change_version_and_others_get_404(): void
    {
        $exam = $this->printedExam(mcq: 2, versions: 2);
        $scanId = $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, [1 => 1, 2 => 1], version: 1))->assertCreated()->json('scan_id');
        Submission::query()->update(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->asUser($this->teacher)->postJson("/api/v1/exam-sheets/{$scanId}/version", ['version_no' => 2])
            ->assertStatus(409)->assertJsonPath('code', 'submission_published');

        $other = $this->makeTeacher($this->teacher->school);
        $this->asUser($other)->postJson("/api/v1/exam-sheets/{$scanId}/version", ['version_no' => 2])->assertNotFound();
        $response = Response::query()->firstOrFail();
        $this->asUser($other)->postJson("/api/v1/exam-responses/{$response->id}/resolve", ['options' => [1]])->assertNotFound();
    }

    public function test_resolving_a_double_mark_scores_the_teachers_reading_by_code(): void
    {
        $exam = $this->printedExam(mcq: 3, numeric: 1);
        // Key: every mcq original 1 (ก), the number "1". Row 1 double, row 2 unclear, number unreadable.
        $reading = $this->reading($exam, 1, [1 => [1, 2], 2 => 1, 3 => 1, 4 => '1']);
        $reading['rows']['2']['3'] = 0.3;
        $reading['digits']['4']['columns'][0]['5'] = 0.9; // two marks in the first column
        $this->upload($exam, $this->students[0], 1, $reading)->assertCreated();
        $r = $this->responses($exam, $this->students[0]);
        $this->assertSame(0.0, $r[1]->ai_score);
        $this->assertSame(['invalid_number'], $r[4]->exam_answer['doubts']);
        $this->assertSame(Submission::STATUS_NEEDS_REVIEW, $this->submission($exam, $this->students[0])->status);

        // The review screen gets the row on the page and the bubbles in sheet order.
        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$r[1]->id}")->assertOk()->json('data.exam');
        $this->assertSame(1, $detail['sheet_no']);
        $this->assertSame(['ก', 'ข', 'ค', 'ง'], $detail['labels']);
        $this->assertSame([1, 2], $detail['selected']);
        $this->assertSame(['double_mark'], $detail['doubts']);
        $this->assertSame(1, $detail['page_no']);
        $this->assertStringContainsString("/scans/{$r[1]->scan_id}/page", $detail['page_image_url']);
        $this->assertIsArray($detail['rect']);
        $queue = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/review-queue?band=check")->assertOk()->json('data');
        $this->assertSame(['sheet_no' => 1, 'version_no' => 1, 'doubts' => ['double_mark'], 'resolved' => false], collect($queue)->firstWhere('id', $r[1]->id)['exam_answer']);

        // Wrong shapes: a value on a row, an option that is not printed, options on a number.
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[1]->id}/resolve", ['value' => '1'])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[1]->id}/resolve", ['options' => [5]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[4]->id}/resolve", ['options' => [1]])
            ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[4]->id}/resolve", ['value' => '1..2'])
            ->assertStatus(422)->assertJsonPath('errors.value.0', 'ตัวเลขไม่ถูกต้อง');

        $data = $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[1]->id}/resolve", ['options' => [1]])
            ->assertOk()->json('data');
        $this->assertEquals(1, $data['final_score']);
        $this->assertSame([1], $data['exam']['resolved']['selected']);
        $resolved = $r[1]->refresh();
        $this->assertSame(1.0, $resolved->ai_score);
        $this->assertSame(1.0, $resolved->final_score);
        $this->assertSame($this->teacher->id, $resolved->reviewed_by);
        $this->assertFalse(ClassRegrade::overridden($resolved));
        $this->assertSame([1], $resolved->exam_answer['resolved']['selected']);
        $this->assertSame([1, 2], $resolved->exam_answer['selected'], 'the raw reading stays');
        $event = ScoreEvent::query()->where('response_id', $resolved->id)->where('action', ScoreEvent::ACTION_OVERRIDE)->firstOrFail();
        $this->assertSame('ครูอ่านรอยฝน', $event->reason);

        // Resolving again replaces the reading.
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[1]->id}/resolve", ['options' => [2]])->assertOk();
        $this->assertSame(0.0, $r[1]->refresh()->final_score);
        $this->assertSame([2], $r[1]->exam_answer['resolved']['selected']);

        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[2]->id}/resolve", ['options' => []])->assertOk();
        $this->assertSame(['no_answer'], $r[2]->refresh()->final_error_types);
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[4]->id}/resolve", ['value' => '01.0'])->assertOk();
        $this->assertSame('1', $r[4]->refresh()->exam_answer['resolved']['value']);
        $this->assertSame(1.0, $r[4]->final_score);

        $this->assertSame(Submission::STATUS_REVIEWED, $this->submission($exam, $this->students[0])->status);
        $status = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertOk()->json();
        $this->assertSame(0, $status['data'][0]['doubt_count']);
        $this->assertSame(1, $status['summary']['ready_to_publish']);

        // Published answers are frozen.
        Submission::query()->update(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$r[1]->id}/resolve", ['options' => [1]])
            ->assertStatus(409)->assertJsonPath('code', 'submission_published');
    }

    public function test_resolve_takes_positions_on_the_students_sheet_and_stores_original_ones(): void
    {
        $exam = $this->printedExam(mcq: 6, versions: 2);
        $keys = ExamScanKit::keys($exam)[2];
        $shuffled = collect($keys)->first(fn (array $i) => $i['option_order'] !== null && $i['option_order'] !== [1, 2, 3, 4]);
        $this->assertNotNull($shuffled, 'version ข shuffles some options');
        $marks = [$shuffled['sheet_no'] => [1, 2]];
        $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, $marks, version: 2))->assertCreated();
        $response = Response::query()->where('question_id', $shuffled['question_id'])->firstOrFail();
        $displayedRight = $shuffled['accepted_options'][0];

        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$response->id}/resolve", ['options' => [$displayedRight]])
            ->assertOk()
            ->assertJsonPath('data.exam.resolved.selected', [$displayedRight])
            ->assertJsonPath('data.exam.version_label', 'ข');
        $response->refresh();
        $this->assertSame([1], $response->exam_answer['resolved']['selected']); // the key is original ก
        $this->assertSame(1.0, $response->final_score);
    }

    public function test_class_regrade_of_an_exam_rescores_by_code_without_a_gemini_key(): void
    {
        $exam = $this->printedExam(mcq: 3);
        foreach ($this->students as $i => $student) {
            // Student 1: ก ข [ก,ข]; student 2: ข ข ข; student 3: ก ก ก.
            $marks = match ($i) {
                0 => [1 => 1, 2 => 2, 3 => [1, 2]],
                1 => [1 => 2, 2 => 2, 3 => 2],
                default => [1 => 1, 2 => 1, 3 => 1],
            };
            $this->upload($exam, $student, 1, $this->reading($exam, 1, $marks))->assertCreated();
        }
        $a = $this->responses($exam, $this->students[0]);
        $b = $this->responses($exam, $this->students[1]);
        // Student 1's double mark read as ข by the teacher; student 2's first answer overridden to 1 point.
        $this->asUser($this->teacher)->postJson("/api/v1/exam-responses/{$a[3]->id}/resolve", ['options' => [2]])->assertOk();
        $this->assertSame(0.0, $a[3]->refresh()->final_score);
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$b[1]->id}", [
            'final_score' => 1, 'final_understanding' => 'good', 'reason' => 'ยอมรับคำตอบ',
        ])->assertOk();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/publish")->assertOk();
        $this->assertSame(Submission::STATUS_PUBLISHED, $this->submission($exam, $this->students[2])->status);

        // The key of questions 2 and 3 becomes ข.
        $questions = Question::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $this->asUser($this->teacher)->putJson("/api/v1/exams/{$exam->id}/answer-key", ['answers' => [
            ['question_id' => $questions[1]->id, 'accepted_options' => [2]],
            ['question_id' => $questions[2]->id, 'accepted_options' => [2]],
        ]])->assertOk();

        $estimate = $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/regrade/estimate")->assertOk()->json('data');
        // Student 1: q2 0->1, q3 (resolved ข) 0->1; student 2: q2, q3 0->1; student 3: q2, q3 1->0.
        $this->assertSame(6, $estimate['mcq_by_code']);
        $this->assertSame(3, $estimate['submissions']);
        $this->assertSame(1, $estimate['skipped_overridden']);
        $this->assertSame(3, $estimate['published_submissions']);
        $this->assertSame(0, $estimate['queued_responses']);
        $this->assertEquals(0, $estimate['estimate']['thb']);

        Queue::fake();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/regrade")
            ->assertStatus(202)
            ->assertJsonPath('data.rescored_by_code', 6)
            ->assertJsonPath('data.queued_submissions', 3);
        Queue::assertPushedOn('grading', RescoreExamJob::class);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/regrade")
            ->assertStatus(409)->assertJsonPath('code', 'regrade_in_progress');
        $job = Queue::pushed(RescoreExamJob::class)->first();
        app()->call([$job, 'handle']);

        $a = $this->responses($exam, $this->students[0]);
        $this->assertSame(1.0, $a[3]->ai_score, 'the resolved ข is scored, not the double mark');
        $this->assertSame(1.0, $a[3]->final_score);
        $this->assertNotNull($a[3]->reviewed_at);
        $this->assertSame(1.0, $a[2]->final_score);
        $b = $this->responses($exam, $this->students[1]);
        $this->assertSame(1.0, $b[1]->final_score, 'the override is kept');
        $this->assertSame(0.0, $b[1]->ai_score);
        $c = $this->responses($exam, $this->students[2]);
        $this->assertSame(0.0, $c[2]->final_score);
        $this->assertSame(1, ScoreEvent::query()->where('response_id', $c[2]->id)->where('action', ScoreEvent::ACTION_RESCAN)->count());
        // Published submissions with a change are reopened; clean ones are ready to publish again.
        foreach ($this->students as $student) {
            $this->assertSame(Submission::STATUS_REVIEWED, $this->submission($exam, $student)->status);
        }
        $this->assertFalse(ClassRegrade::overridden($a[3]));

        // Nothing left to change: 200 and no job.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/regrade")
            ->assertOk()->assertJsonPath('data.rescored_by_code', 0);
        Queue::assertPushed(RescoreExamJob::class, 1);

        // include_overridden scores the overridden answer by code too.
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/regrade", ['include_overridden' => true])
            ->assertStatus(202)->assertJsonPath('data.rescored_by_code', 1);
        app()->call([Queue::pushed(RescoreExamJob::class)->last(), 'handle']);
        $this->assertSame(0.0, $this->responses($exam, $this->students[1])[1]->final_score);

        $manual = $this->createExam(['grading_method' => 'manual', 'manual_full_marks' => 20]);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$manual->id}/regrade")
            ->assertStatus(422)->assertJsonPath('code', 'exam_manual_grading');
    }

    public function test_publishing_the_class_skips_sheets_that_are_not_complete(): void
    {
        $exam = $this->printedExam(mcq: 120);
        $keys = ExamScanKit::keys($exam)[1];
        $right = fn (int $page) => collect($keys)
            ->filter(fn (array $i) => ($page === 1) === ($i['sheet_no'] <= 100))
            ->mapWithKeys(fn (array $i) => [$i['sheet_no'] => $i['accepted_options'][0]])
            ->all();
        // Student 1: both pages clean. Student 2: page 1 only. Student 3: a double mark.
        $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, $right(1)))->assertCreated();
        $this->upload($exam, $this->students[0], 2, $this->reading($exam, 2, $right(2)))->assertCreated();
        $this->upload($exam, $this->students[1], 1, $this->reading($exam, 1, $right(1)))->assertCreated();
        $doubtful = $right(1);
        $doubtful[1] = [1, 2];
        $this->upload($exam, $this->students[2], 1, $this->reading($exam, 1, $doubtful))->assertCreated();
        $this->upload($exam, $this->students[2], 2, $this->reading($exam, 2, $right(2)))->assertCreated();

        $summary = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertOk()->json('summary');
        $this->assertSame(['published' => 0, 'ready_to_publish' => 1, 'waiting_review' => 2], array_intersect_key($summary, array_flip(['published', 'ready_to_publish', 'waiting_review'])));
        // Page 1 alone is fully reviewed, yet publishing it would send half a score.
        $this->asUser($this->teacher)->postJson('/api/v1/submissions/'.$this->submission($exam, $this->students[1])->id.'/publish')
            ->assertStatus(409)
            ->assertJsonPath('code', 'submission_not_reviewed')
            ->assertJsonPath('errors.missing_pages', ['2']);
        $queueMeta = $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$exam->id}/review-queue")->assertOk()->json('meta.submissions');
        $this->assertSame([true, false, false], array_column($queueMeta, 'publishable'));

        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/publish")
            ->assertOk()
            ->assertJsonPath('data.published', 1)
            ->assertJsonPath('data.skipped', 2);
        $published = $this->submission($exam, $this->students[0]);
        $this->assertSame(Submission::STATUS_PUBLISHED, $published->status);
        $this->assertEquals(120, $published->total_score);
        $summary = $this->asUser($this->teacher)->getJson("/api/v1/exams/{$exam->id}/sheet-status")->assertOk()->json('summary');
        $this->assertSame(1, $summary['published']);
    }

    public function test_students_see_the_score_only_unless_the_key_is_shown(): void
    {
        $exam = $this->printedExam(mcq: 2, numeric: 1, versions: 2);
        $keys = ExamScanKit::keys($exam)[2];
        $marks = [];
        foreach ($keys as $sheetNo => $item) {
            $marks[$sheetNo] = $item['type'] === 'numeric' ? '2' : $item['accepted_options'][0];
        }
        $this->upload($exam, $this->students[0], 1, $this->reading($exam, 1, $marks, version: 2))->assertCreated();
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$exam->id}/publish")->assertOk()->assertJsonPath('data.published', 1);
        $submission = $this->submission($exam, $this->students[0]);
        $student = $this->students[0];

        $list = $this->asUser($student, ['student'])->getJson('/api/v1/student/results')->assertOk()->json('data.0');
        $this->assertSame('exam', $list['kind']);
        $data = $this->asUser($student, ['student'])->getJson("/api/v1/student/results/{$submission->id}")->assertOk()->json('data');
        $this->assertSame('exam', $data['kind']);
        $this->assertSame('ข', $data['version_label']);
        $this->assertEquals(2, $data['total']);
        $this->assertEquals(3, $data['max']);
        $this->assertCount(2, $data['sections']);
        $this->assertEquals(2, $data['sections'][0]['score']);
        $this->assertEquals(1, $data['sections'][1]['max']);
        $this->assertNull($data['items']);
        $this->assertSame([], $data['responses']);
        $numeric = Response::query()->whereHas('question', fn ($q) => $q->where('type', 'numeric'))->firstOrFail();
        $this->asUser($student, ['student'])->postJson("/api/v1/student/responses/{$numeric->id}/appeal", ['reason' => 'ตอบ 2 ถูกแล้ว'])
            ->assertNotFound();

        $this->asUser($this->teacher)->patchJson("/api/v1/assignments/{$exam->id}", ['show_key_to_students' => true])->assertOk();
        $items = $this->asUser($student, ['student'])->getJson("/api/v1/student/results/{$submission->id}")->assertOk()->json('data.items');
        $this->assertCount(3, $items);
        $this->assertSame([1, 2, 3], array_column($items, 'number'));
        foreach ($items as $item) {
            if ($item['type'] === 'numeric') {
                $this->assertSame('2', $item['marked_value']);
                $this->assertSame(['1'], $item['correct_values']);
                $this->assertEquals(0, $item['score']);

                continue;
            }
            // Labels of the student's own version: the mark is where version ข printed the key.
            $this->assertSame($item['correct'], $item['marked']);
            $label = ['ก', 'ข', 'ค', 'ง'][$keys[$item['number']]['accepted_options'][0] - 1];
            $this->assertSame([$label], $item['marked']);
            $this->assertEquals(1, $item['score']);
        }
        $this->asUser($student, ['student'])->postJson("/api/v1/student/responses/{$numeric->id}/appeal", ['reason' => 'ตอบ 2 ถูกแล้ว'])
            ->assertCreated();
        $this->assertSame(1, Appeal::query()->count());

        // A classmate sees nothing of it.
        $this->asUser($this->students[1], ['student'])->getJson("/api/v1/student/results/{$submission->id}")->assertNotFound();
    }
}
