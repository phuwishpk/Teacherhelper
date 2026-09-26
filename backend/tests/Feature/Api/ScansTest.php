<?php

namespace Tests\Feature\Api;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Scans\ScanIngestor;
use App\Domain\Scans\SubmissionStatus;
use App\Exceptions\ApiException;
use App\Jobs\GradeScanJob;
use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\Submission;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * DESIGN §9.4 POST /scans: idempotent upload, QR / layout / page checks,
 * storage per §7.3, responses per region, mcq graded on upload (§11.6), the
 * rescan rules (superseded / pending_confirm + confirm-replace) and the
 * submission status transitions (§8.4).
 */
class ScansTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
    }

    public function test_a_page_is_stored_with_its_crops_and_responses(): void
    {
        $meta = $this->metaFor(1);

        $res = $this->postScan($meta)->assertStatus(201)->assertJsonPath('state', 'active');
        $this->assertSame(['scan_id', 'submission_id', 'state'], array_keys($res->json()));

        $scan = Scan::query()->findOrFail($res->json('scan_id'));
        $submission = Submission::query()->findOrFail($res->json('submission_id'));
        $this->assertSame($this->assignment->id, $submission->assignment_id);
        $this->assertSame($this->student->id, $submission->student_id);
        $this->assertSame($meta['client_scan_id'], $scan->client_scan_id);
        $this->assertSame(1, $scan->page_no);
        $this->assertSame(1, $scan->layout_version);
        $this->assertSame($this->teacher->id, $scan->uploaded_by);
        $this->assertSame('2026-09-20 02:15:00', $scan->scanned_at->utc()->format('Y-m-d H:i:s')); // stored in UTC
        $this->assertEqualsWithDelta(182.4, $scan->blur_score, 0.001);

        $disk = Storage::disk('local');
        $school = $this->assignment->school_id;
        $a = $this->assignment->id;
        $this->assertSame("scans/{$school}/{$a}/{$scan->id}.webp", $scan->page_image_path);
        $this->assertSame($this->fixtureBytes('page.webp'), $disk->get($scan->page_image_path));

        $mcq = Response::query()->where('question_id', $this->mcq->id)->firstOrFail();
        $short = Response::query()->where('question_id', $this->short->id)->firstOrFail();
        $this->assertSame("crops/{$school}/{$a}/{$mcq->id}.webp", $mcq->crop_path);
        $this->assertTrue($disk->exists($mcq->crop_path));
        $this->assertTrue($disk->exists($short->crop_path));
        $this->assertNull($short->final_crop_path);

        // mcq: graded now from the bubble fill (§11.6)
        $this->assertSame('scored', $mcq->grading_state);
        $this->assertSame(['A' => 0.04, 'B' => 0.83, 'C' => 0.06, 'D' => 0.05], $mcq->mcq_fill);
        $this->assertSame(1.0, $mcq->ai_score);
        $this->assertSame('good', $mcq->ai_understanding);
        $this->assertSame('confident', $mcq->priority_band);
        $this->assertSame(0.0, $mcq->review_priority);
        $this->assertSame('mcq', $mcq->fuzzy_trace['system']);
        $this->assertDatabaseHas('score_events', [
            'response_id' => $mcq->id, 'action' => 'ai_scored', 'actor' => 'system', 'new_understanding' => 'good',
        ]);

        // short: waits for GradeScanJob with the phone's readings
        $this->assertSame('queued', $short->grading_state);
        $this->assertEqualsWithDelta(0.08, $short->ink_ratio, 0.0001);
        $this->assertSame('20', $short->cnn_text);
        $this->assertEqualsWithDelta(0.97, $short->cnn_confidence, 0.0001);
        $this->assertNull($short->mcq_fill);
        $this->assertSame(0, ScoreEvent::query()->where('response_id', $short->id)->count());

        $this->assertSame('grading', $submission->status);
        Queue::assertPushedOn('grading', GradeScanJob::class, fn (GradeScanJob $job) => $job->scanId === $scan->id);
    }

    public function test_the_show_work_final_answer_crop_is_stored_separately(): void
    {
        $res = $this->postScan($this->metaFor(2))->assertStatus(201);

        $work = Response::query()->where('question_id', $this->work->id)->firstOrFail();
        $open = Response::query()->where('question_id', $this->open->id)->firstOrFail();
        $this->assertSame($res->json('scan_id'), $work->scan_id);
        $this->assertStringEndsWith("/{$work->id}_final.webp", (string) $work->final_crop_path);
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), Storage::disk('local')->get($work->final_crop_path));
        $this->assertSame('5', $work->cnn_text);
        $this->assertNull($open->final_crop_path);
        $this->assertSame(['queued', 'queued'], [$work->grading_state, $open->grading_state]);
    }

    public function test_an_mcq_only_page_goes_straight_to_needs_review(): void
    {
        $page = $this->layoutPages(1)[0];
        $page['regions'] = [$page['regions'][0]];
        $this->assignment->layouts()->where('version', 1)->update(['pages' => json_encode([$page])]);
        $meta = $this->metaFor(1);
        $meta['regions'] = [$meta['regions'][0]];

        $res = $this->postScan($meta)->assertStatus(201);

        $this->assertSame('needs_review', Submission::query()->findOrFail($res->json('submission_id'))->status);
        Queue::assertNotPushed(GradeScanJob::class);
    }

    public function test_an_abstaining_digit_reader_leaves_cnn_empty(): void
    {
        $meta = $this->metaFor(1);
        $meta['regions'][1]['cnn'] = [];

        $this->postScan($meta)->assertStatus(201);

        $short = Response::query()->where('question_id', $this->short->id)->firstOrFail();
        $this->assertNull($short->cnn_text);
        $this->assertNull($short->cnn_confidence);
    }

    public function test_a_retry_with_the_same_client_scan_id_replays_the_first_answer(): void
    {
        $meta = $this->metaFor(1);
        $first = $this->postScan($meta)->assertStatus(201);

        $this->postScan($meta)->assertStatus(200)->assertExactJson($first->json());
        // Case of the UUID does not matter.
        $this->postScan([...$meta, 'client_scan_id' => strtoupper($meta['client_scan_id'])])->assertStatus(200);

        $this->assertSame(1, Scan::query()->count());
        $this->assertSame(2, Response::query()->count());
        Queue::assertPushed(GradeScanJob::class, 1);
    }

    public function test_a_replay_is_answered_even_without_the_files(): void
    {
        $meta = $this->metaFor(1);
        $this->postScan($meta)->assertStatus(201);

        $this->postScan($meta, [])->assertStatus(200)->assertJsonPath('state', 'active');
    }

    public function test_another_teacher_cannot_replay_someone_elses_scan(): void
    {
        $meta = $this->metaFor(1);
        $this->postScan($meta)->assertStatus(201);
        $other = $this->makeTeacher($this->assignment->school);

        $this->postScan($meta, null, $other)->assertStatus(403)->assertJsonPath('code', 'forbidden');
    }

    public function test_a_forged_or_malformed_qr_is_rejected(): void
    {
        $good = $this->qr(1);
        $forged = substr($good, 0, -8).($good[-8] === 'A' ? 'B' : 'A').substr($good, -7);

        foreach ([$forged, 'EV1.1.2.3', 'not a qr', 'EVL1.abc'] as $qr) {
            $this->postScan($this->metaFor(1, qr: $qr))
                ->assertStatus(422)
                ->assertJsonPath('code', 'qr_invalid');
        }
        $this->assertSame(0, Scan::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_validly_signed_qr_for_a_missing_assignment_is_qr_invalid(): void
    {
        $this->postScan($this->metaFor(1, qr: $this->qr(1, assignmentId: 999999)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'qr_invalid');
    }

    public function test_an_unknown_layout_version_is_rejected(): void
    {
        $this->postScan($this->metaFor(1, qr: $this->qr(1, version: 9)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'layout_unknown');
    }

    public function test_a_page_outside_the_layout_is_page_mismatch(): void
    {
        $this->postScan($this->metaFor(1, qr: $this->qr(3)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'page_mismatch');
    }

    public function test_regions_of_another_page_are_page_mismatch(): void
    {
        // Page 2's crops sent with page 1's QR.
        $meta = $this->metaFor(2, qr: $this->qr(1));

        $this->postScan($meta)
            ->assertStatus(422)
            ->assertJsonPath('code', 'page_mismatch')
            ->assertJsonStructure(['errors' => ['meta.regions', 'meta.regions.0.region_id']]);
    }

    public function test_a_missing_region_or_a_wrong_question_id_is_page_mismatch(): void
    {
        $missing = $this->metaFor(1);
        array_pop($missing['regions']);
        $this->postScan($missing)->assertStatus(422)->assertJsonPath('code', 'page_mismatch');

        $wrong = $this->metaFor(1);
        $wrong['regions'][1]['question_id'] = $this->work->id;
        $this->postScan($wrong)
            ->assertStatus(422)
            ->assertJsonPath('code', 'page_mismatch')
            ->assertJsonStructure(['errors' => ['meta.regions.1.question_id']]);
    }

    public function test_the_anonymous_spare_sheet_and_unenrolled_students_are_student_unknown(): void
    {
        $this->postScan($this->metaFor(1, qr: $this->qr(1, studentId: 0)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_unknown');

        $elsewhere = $this->enrollStudent($this->makeClassroom($this->teacher), 3)['student'];
        $this->postScan($this->metaFor(1, qr: $this->qr(1, studentId: $elsewhere->id)))
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_unknown');
    }

    public function test_only_the_teacher_of_the_classroom_may_upload(): void
    {
        $sameSchool = $this->makeTeacher($this->assignment->school);
        $this->postScan($this->metaFor(1), null, $sameSchool)->assertStatus(403)->assertJsonPath('code', 'forbidden');

        $otherSchool = $this->makeTeacher();
        $this->postScan($this->metaFor(1), null, $otherSchool)->assertStatus(403);

        // A student token never reaches the endpoint (ability teacher).
        $this->postScan($this->metaFor(1), null, $this->student)->assertStatus(403);

        $this->asGuest()->post('/api/v1/scans', [], ['Accept' => 'application/json'])->assertStatus(401);
        $this->assertSame(0, Scan::query()->count());
    }

    public function test_a_missing_or_broken_meta_is_a_validation_error(): void
    {
        $this->asUser($this->teacher)->post('/api/v1/scans', ['page' => $this->webp('page.webp')], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['meta']]);

        $this->asUser($this->teacher)->post('/api/v1/scans', ['meta' => '{not json'], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed');

        $meta = $this->metaFor(1);
        unset($meta['blur_score']);
        $meta['client_scan_id'] = 'not-a-uuid';
        $this->postScan($meta)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['meta.blur_score', 'meta.client_scan_id']]);
    }

    public function test_crops_must_be_present_and_webp(): void
    {
        $meta = $this->metaFor(1);
        $files = $this->filesFor($meta);
        unset($files['crop_q'.$this->short->id]);
        $this->postScan($meta, $files)
            ->assertStatus(422)
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonStructure(['errors' => ['crop_q'.$this->short->id]]);

        $files = $this->filesFor($meta);
        $files['page'] = UploadedFile::fake()->create('page.jpg', 10, 'image/jpeg');
        $this->postScan($meta, $files)->assertStatus(422)->assertJsonStructure(['errors' => ['page']]);

        $page2 = $this->metaFor(2);
        unset($page2['regions'][0]['final_file']);
        $this->postScan($page2)->assertStatus(422)->assertJsonStructure(['errors' => ['meta.regions.0.final_file']]);

        $this->assertSame(0, Scan::query()->count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_meta_validation_messages_are_in_thai(): void
    {
        $meta = $this->metaFor(1);
        $meta['regions'][0]['mcq_fill'] = ['A' => 0.1, 'B' => 'x'];
        $meta['regions'][1]['question_id'] = 'abc';
        $meta['regions'][1]['cnn'] = ['text' => str_repeat('1', 40), 'confidence' => 0.9];
        $meta['blur_score'] = 'sharp';

        $res = $this->postScan($meta)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

        $errors = $res->json('errors');
        $this->assertSame('ค่าการฝนของตัวเลือก ต้องเป็นตัวเลข', $errors['meta.regions.0.mcq_fill.B'][0]);
        $this->assertSame('question_id ของช่องคำตอบ ต้องเป็นจำนวนเต็ม', $errors['meta.regions.1.question_id'][0]);
        $this->assertSame('ข้อความจากตัวอ่านเลข ยาวได้ไม่เกิน 32 ตัวอักษร', $errors['meta.regions.1.cnn.text'][0]);
        $this->assertSame('ค่าความคมชัดของภาพ (blur_score) ต้องเป็นตัวเลข', $errors['meta.blur_score'][0]);
        foreach ([$res->json('message'), ...array_merge(...array_values($errors))] as $message) {
            $this->assertMatchesRegularExpression('/\p{Thai}/u', $message);
            $this->assertDoesNotMatchRegularExpression('/\b(field|must|more errors?)\b/i', $message);
        }
    }

    public function test_files_dropped_by_max_file_uploads_are_a_retryable_server_error(): void
    {
        $uploaded = ['page' => 'x', 'crop_a' => 'x', 'crop_b' => 'x'];

        try {
            ScanIngestor::assertUploadNotTruncated($uploaded, ['page', 'crop_a', 'crop_b', 'crop_c', 'crop_d'], 3);
            $this->fail('expected too_many_files');
        } catch (ApiException $e) {
            // 5xx: the app keeps the scan and retries after the limit is raised.
            $this->assertSame(503, $e->status);
            $this->assertSame('too_many_files', $e->errorCode);
            $this->assertStringContainsString('max_file_uploads', $e->getMessage());
        }

        // Under the limit, or a page that fits the limit: nothing to report.
        ScanIngestor::assertUploadNotTruncated($uploaded, ['page', 'crop_a', 'crop_b'], 3);
        ScanIngestor::assertUploadNotTruncated(['page' => 'x'], ['page', 'crop_a'], 20);
        ScanIngestor::assertUploadNotTruncated($uploaded, ['page', 'crop_a', 'crop_b', 'crop_c'], 0);
    }

    public function test_mcq_fill_must_use_the_printed_options(): void
    {
        $this->postScan($this->metaFor(1, fill: ['A' => 0.1, 'E' => 0.9]))
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['meta.regions.0.mcq_fill']]);

        $meta = $this->metaFor(1);
        unset($meta['regions'][0]['mcq_fill']);
        $this->postScan($meta)->assertStatus(422)->assertJsonStructure(['errors' => ['meta.regions.0.mcq_fill']]);
    }

    public function test_wrong_blank_and_double_marked_mcq_answers(): void
    {
        $cases = [
            'wrong' => [['A' => 0.9, 'B' => 0.05, 'C' => 0.02, 'D' => 0.01], 0.0, 'not_yet', 'confident', []],
            'blank' => [['A' => 0.01, 'B' => 0.02, 'C' => 0.02, 'D' => 0.01], 0.0, 'not_yet', 'confident', ['no_answer']],
            'double' => [['A' => 0.7, 'B' => 0.8, 'C' => 0.02, 'D' => 0.01], 0.0, 'not_yet', 'check', []],
            'faint' => [['A' => 0.3, 'B' => 0.8, 'C' => 0.02, 'D' => 0.01], 1.0, 'good', 'check', []],
        ];

        foreach ($cases as $name => [$fill, $score, $understanding, $band, $errors]) {
            ScoreEvent::query()->delete();
            Response::query()->delete();
            $this->postScan($this->metaFor(1, fill: $fill))->assertStatus(201);

            $mcq = Response::query()->where('question_id', $this->mcq->id)->firstOrFail();
            $this->assertSame($score, $mcq->ai_score, $name);
            $this->assertSame($understanding, $mcq->ai_understanding, $name);
            $this->assertSame($band, $mcq->priority_band, $name);
            $this->assertSame($errors, $mcq->ai_error_types, $name);
        }
    }

    public function test_a_question_whose_type_changed_after_printing_goes_to_manual(): void
    {
        $this->mcq->update(['type' => Question::TYPE_SHORT, 'answer_key' => ['accepted' => ['B']]]);

        $this->postScan($this->metaFor(1))->assertStatus(201);

        $response = Response::query()->where('question_id', $this->mcq->id)->firstOrFail();
        $this->assertSame('manual', $response->grading_state);
        $this->assertSame('layout_type_mismatch', $response->fuzzy_trace['manual_reason']);
    }

    public function test_a_question_deleted_after_printing_is_skipped(): void
    {
        // The sheet was printed with layout v1; the short question was removed since.
        $this->short->delete();

        $this->postScan($this->metaFor(1))->assertStatus(201);

        $this->assertSame([$this->mcq->id], Response::query()->pluck('question_id')->all());
    }

    public function test_a_question_with_scanned_responses_cannot_be_deleted(): void
    {
        $this->postScan($this->metaFor(1))->assertStatus(201);

        $this->asUser($this->teacher)->deleteJson("/api/v1/questions/{$this->short->id}")
            ->assertStatus(409)
            ->assertJsonPath('code', 'question_has_responses');
        $this->assertNotNull($this->short->fresh());
    }

    public function test_a_rescan_before_publishing_supersedes_the_old_scan_and_regrades_the_page(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        $mcq = Response::query()->where('question_id', $this->mcq->id)->firstOrFail();
        $short = Response::query()->where('question_id', $this->short->id)->firstOrFail();
        // Pretend grading and a teacher review happened.
        $short->update(['grading_state' => 'scored', 'ai_score' => 2, 'ai_understanding' => 'good', 'attempts' => 1,
            'extraction' => ['blank' => false], 'final_score' => 1.5, 'reviewed_by' => $this->teacher->id, 'reviewed_at' => now()]);
        $page2 = $this->postScan($this->metaFor(2))->assertStatus(201);

        $second = $this->postScan($this->metaFor(1, fill: ['A' => 0.9, 'B' => 0.02, 'C' => 0.02, 'D' => 0.01]), $this->filesFor($this->metaFor(1), 'crop_alt.webp'))
            ->assertStatus(201)
            ->assertJsonPath('state', 'active')
            ->assertJsonPath('submission_id', $first->json('submission_id'));

        $this->assertSame('superseded', Scan::query()->findOrFail($first->json('scan_id'))->state);
        $this->assertSame('active', Scan::query()->findOrFail($page2->json('scan_id'))->state); // other page untouched

        $mcq->refresh();
        $short->refresh();
        $this->assertSame($second->json('scan_id'), $mcq->scan_id);
        $this->assertSame(0.0, $mcq->ai_score); // now answered A
        $this->assertSame($second->json('scan_id'), $short->scan_id);
        $this->assertSame('queued', $short->grading_state);
        $this->assertSame(0, $short->attempts);
        $this->assertNull($short->extraction);
        $this->assertNull($short->ai_score);
        $this->assertNull($short->final_score);
        $this->assertNull($short->reviewed_at);
        // Same response id, so the crop path is reused with the new image.
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), Storage::disk('local')->get($short->crop_path));
        $this->assertSame(2, Response::query()->where('submission_id', $first->json('submission_id'))->where('scan_id', $second->json('scan_id'))->count());

        $this->assertDatabaseHas('score_events', ['response_id' => $short->id, 'action' => 'rescan', 'actor' => 'teacher',
            'actor_user_id' => $this->teacher->id, 'old_score' => 1.5, 'new_score' => null]);
        $this->assertDatabaseHas('score_events', ['response_id' => $mcq->id, 'action' => 'rescan', 'old_score' => 1]);
        $this->assertSame(2, ScoreEvent::query()->where('response_id', $mcq->id)->where('action', 'ai_scored')->count());
        $this->assertSame('grading', Submission::query()->findOrFail($first->json('submission_id'))->status);
    }

    public function test_a_failed_crop_write_during_a_rescan_restores_the_previous_crops(): void
    {
        $first = $this->postScan($this->metaFor(2))->assertStatus(201);
        $work = Response::query()->where('question_id', $this->work->id)->firstOrFail();
        $open = Response::query()->where('question_id', $this->open->id)->firstOrFail();
        $disk = Storage::disk('local');
        $before = [
            $work->crop_path => $disk->get($work->crop_path),
            $work->final_crop_path => $disk->get($work->final_crop_path),
            $open->crop_path => $disk->get($open->crop_path),
        ];
        $this->assertSame($this->fixtureBytes('crop.webp'), $before[$work->crop_path]);
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), $before[$work->final_crop_path]);
        $pageFiles = $disk->allFiles("scans/{$this->assignment->school_id}/{$this->assignment->id}");

        // The work crops are replaced first; writing the open crop then fails.
        $this->failDiskWritesTo($open->crop_path);
        $meta = $this->metaFor(2);
        $files = $this->filesFor($meta, 'crop_alt.webp');
        $files['crop_q'.$this->work->id.'_final'] = $this->webp('crop.webp');
        $this->postScan($meta, $files)->assertStatus(500);

        foreach ($before as $path => $bytes) {
            $this->assertSame($bytes, $disk->get($path), $path);
        }
        $this->assertSame($first->json('scan_id'), $work->fresh()->scan_id);
        $this->assertSame($first->json('scan_id'), $open->fresh()->scan_id);
        $this->assertSame(1, Scan::query()->count());
        $this->assertSame('active', Scan::query()->findOrFail($first->json('scan_id'))->state);
        $this->assertSame($pageFiles, $disk->allFiles("scans/{$this->assignment->school_id}/{$this->assignment->id}"));
        $this->assertSame([], $disk->allFiles("crops/{$this->assignment->school_id}/{$this->assignment->id}/replaced"));
    }

    public function test_a_rescan_without_the_final_answer_box_deletes_the_old_final_crop(): void
    {
        $this->postScan($this->metaFor(2))->assertStatus(201);
        $work = Response::query()->where('question_id', $this->work->id)->firstOrFail();
        $oldFinal = $work->final_crop_path;
        $disk = Storage::disk('local');
        $this->assertTrue($disk->exists($oldFinal));

        // Layout v2 printed the question without a final-answer box.
        $pages = $this->layoutPages(2);
        unset($pages[1]['regions'][0]['final_answer']);
        Layout::create(['assignment_id' => $this->assignment->id, 'version' => 2, 'pages' => $pages]);
        $meta = $this->metaFor(2, qr: $this->qr(2, version: 2));
        unset($meta['regions'][0]['final_file']);
        $this->postScan($meta, $this->filesFor($meta, 'crop_alt.webp'))->assertStatus(201);

        $work->refresh();
        $this->assertNull($work->final_crop_path);
        $this->assertFalse($disk->exists($oldFinal));
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), $disk->get($work->crop_path));
        $this->assertSame([], $disk->allFiles("crops/{$this->assignment->school_id}/{$this->assignment->id}/replaced"));
        $this->asUser($this->teacher)->get("/api/v1/responses/{$work->id}/crop?part=final", ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_a_rescan_of_a_published_submission_waits_for_confirmation(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        $submission = Submission::query()->findOrFail($first->json('submission_id'));
        $short = Response::query()->where('question_id', $this->short->id)->firstOrFail();
        $short->update(['grading_state' => 'scored', 'ai_score' => 2, 'ai_understanding' => 'good', 'final_score' => 2,
            'final_understanding' => 'good', 'reviewed_by' => $this->teacher->id, 'reviewed_at' => now()]);
        $submission->update(['status' => 'published', 'published_at' => now(), 'published_by' => $this->teacher->id, 'total_score' => 3]);
        // The published answer counts for mastery (§14.2) until the rescan is confirmed.
        $this->short->skills()->attach(Skill::factory()->create(['subject_id' => $this->assignment->subject_id])->id);
        app(MasteryCalculator::class)->recordSubmission($submission->id);
        $this->assertSame(1, SkillObservation::query()->count());
        $this->assertSame(1, Mastery::query()->where('student_id', $submission->student_id)->count());
        Queue::fake();

        $meta = $this->metaFor(1, fill: ['A' => 0.9, 'B' => 0.02, 'C' => 0.02, 'D' => 0.01]);
        $pending = $this->postScan($meta, $this->filesFor($meta, 'crop_alt.webp'))
            ->assertStatus(202)
            ->assertJsonPath('state', 'pending_confirm')
            ->assertJsonPath('submission_id', $submission->id);
        $pendingId = $pending->json('scan_id');
        $this->assertIsInt($pendingId);

        // Nothing published changes until the teacher confirms.
        $this->assertSame('active', Scan::query()->findOrFail($first->json('scan_id'))->state);
        $short->refresh();
        $this->assertSame($first->json('scan_id'), $short->scan_id);
        $this->assertSame(2.0, $short->final_score);
        $this->assertSame($this->fixtureBytes('crop.webp'), Storage::disk('local')->get($short->crop_path));
        $this->assertSame('published', $submission->fresh()->status);
        Queue::assertNothingPushed();

        // A retry of the upload reports the waiting state (the app keeps it as a conflict).
        $this->postScan($meta, [])->assertStatus(200)->assertJsonPath('state', 'pending_confirm');

        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pendingId}/confirm-replace")
            ->assertOk()
            ->assertExactJson(['scan_id' => $pendingId, 'submission_id' => $submission->id, 'state' => 'active']);

        $this->assertSame('superseded', Scan::query()->findOrFail($first->json('scan_id'))->state);
        $short->refresh();
        $this->assertSame($pendingId, $short->scan_id);
        $this->assertSame('queued', $short->grading_state);
        $this->assertNull($short->final_score);
        $this->assertSame($this->fixtureBytes('crop_alt.webp'), Storage::disk('local')->get($short->crop_path));
        $this->assertDatabaseHas('score_events', ['response_id' => $short->id, 'action' => 'rescan', 'old_score' => 2,
            'old_understanding' => 'good', 'actor_user_id' => $this->teacher->id]);
        $mcq = Response::query()->where('question_id', $this->mcq->id)->firstOrFail();
        $this->assertSame(0.0, $mcq->ai_score);

        $submission->refresh();
        $this->assertSame('grading', $submission->status);
        $this->assertNull($submission->published_at);
        $this->assertNull($submission->published_by);
        // SubmissionReopened: the reopened submission stops counting for mastery until the next publish.
        $this->assertSame(0, SkillObservation::query()->count());
        $this->assertSame(0, Mastery::query()->where('student_id', $submission->student_id)->count());
        $school = $this->assignment->school_id;
        $this->assertSame([], Storage::disk('local')->allFiles("scans/{$school}/{$this->assignment->id}/pending"));
        $this->assertSame([], Storage::disk('local')->allFiles("crops/{$school}/{$this->assignment->id}/replaced"));
        Queue::assertPushedOn('grading', GradeScanJob::class, fn (GradeScanJob $job) => $job->scanId === $pendingId);

        // Confirming again is harmless; the upload retry now reports active.
        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pendingId}/confirm-replace")->assertOk()->assertJsonPath('state', 'active');
        $this->postScan($meta, [])->assertStatus(200)->assertJsonPath('state', 'active');
        Queue::assertPushed(GradeScanJob::class, 1);
    }

    public function test_a_newer_rescan_replaces_an_unconfirmed_one(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);

        $older = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');
        $newer = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');

        $this->assertSame('superseded', Scan::query()->findOrFail($older)->state);
        $this->assertSame('pending_confirm', Scan::query()->findOrFail($newer)->state);
        $this->assertSame('active', Scan::query()->findOrFail($first->json('scan_id'))->state);
        $school = $this->assignment->school_id;
        $this->assertFalse(Storage::disk('local')->exists("scans/{$school}/{$this->assignment->id}/pending/{$older}/regions.json"));
        $this->assertTrue(Storage::disk('local')->exists("scans/{$school}/{$this->assignment->id}/pending/{$newer}/regions.json"));

        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$older}/confirm-replace")
            ->assertStatus(409)
            ->assertJsonPath('code', 'scan_superseded');
    }

    public function test_confirm_replace_is_limited_to_the_teacher_of_the_classroom(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pending = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');

        $this->asUser($this->makeTeacher($this->assignment->school))->postJson("/api/v1/scans/{$pending}/confirm-replace")->assertStatus(403);
        $this->asUser($this->makeTeacher())->postJson("/api/v1/scans/{$pending}/confirm-replace")->assertStatus(404);
        $this->asUser($this->student)->postJson("/api/v1/scans/{$pending}/confirm-replace")->assertStatus(403);
        $this->assertSame('pending_confirm', Scan::query()->findOrFail($pending)->state);
    }

    public function test_a_rescan_whose_stash_was_purged_cannot_be_confirmed(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertStatus(201);
        Submission::query()->whereKey($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now()]);
        $pending = $this->postScan($this->metaFor(1))->assertStatus(202)->json('scan_id');
        Storage::disk('local')->deleteDirectory("scans/{$this->assignment->school_id}/{$this->assignment->id}/pending/{$pending}");

        $this->asUser($this->teacher)->postJson("/api/v1/scans/{$pending}/confirm-replace")
            ->assertStatus(409)
            ->assertJsonPath('code', 'scan_files_missing');
        $this->assertSame('pending_confirm', Scan::query()->findOrFail($pending)->state);
        $this->assertSame('active', Scan::query()->findOrFail($first->json('scan_id'))->state);
    }

    public function test_a_scan_time_ahead_of_the_server_clock_is_clamped_to_now(): void
    {
        $this->freezeSecond();
        $meta = $this->metaFor(1);
        $meta['scanned_at'] = now()->addDays(3)->toIso8601String();

        $id = $this->postScan($meta)->assertStatus(201)->json('scan_id');

        $this->assertTrue(Scan::query()->findOrFail($id)->scanned_at->equalTo(now()));
    }

    public function test_submission_status_follows_grading_and_review(): void
    {
        $page1 = $this->postScan($this->metaFor(1))->assertStatus(201);
        $this->postScan($this->metaFor(2))->assertStatus(201);
        $submission = Submission::query()->findOrFail($page1->json('submission_id'));
        $this->assertSame('grading', $submission->status);

        Response::query()->where('grading_state', 'queued')->update(['grading_state' => 'scored', 'ai_score' => 1]);
        SubmissionStatus::refresh($submission);
        $this->assertSame('needs_review', $submission->status);

        Response::query()->update(['reviewed_at' => now(), 'reviewed_by' => $this->teacher->id]);
        SubmissionStatus::refresh($submission);
        $this->assertSame('reviewed', $submission->status);

        $submission->update(['status' => 'published', 'published_at' => now()]);
        SubmissionStatus::refresh($submission);
        $this->assertSame('published', $submission->fresh()->status); // only publishing or a confirmed rescan changes it
    }

    public function test_scans_work_with_a_layout_built_by_the_server(): void
    {
        $assignment = Assignment::factory()->for_classroom($this->classroom)->create();
        $mcq = Question::factory()->create(['assignment_id' => $assignment->id, 'answer_key' => ['correct' => 'C']]);
        $work = Question::factory()->showWork()->create(['assignment_id' => $assignment->id]);
        $layout = $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$assignment->id}/layout")->assertStatus(201)->json('data.pages.0');

        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => $this->qr(1, 1, assignmentId: $assignment->id),
            'scanned_at' => now()->toIso8601String(),
            'blur_score' => 150,
            'regions' => [],
        ];
        foreach ($layout['regions'] as $region) {
            $entry = ['region_id' => $region['region_id'], 'question_id' => $region['question_id'], 'file' => 'crop_'.$region['region_id']];
            if ($region['kind'] === 'mcq') {
                $entry['mcq_fill'] = array_fill_keys(array_column($region['bubbles'], 'option'), 0.03);
                $entry['mcq_fill']['C'] = 0.77;
            } else {
                $entry['ink_ratio'] = 0.1;
                if (isset($region['final_answer'])) {
                    $entry['final_file'] = 'crop_'.$region['region_id'].'_final';
                }
            }
            $meta['regions'][] = $entry;
        }

        $this->postScan($meta)->assertStatus(201);

        $this->assertSame(1.0, Response::query()->where('question_id', $mcq->id)->value('ai_score'));
        $this->assertNotNull(Response::query()->where('question_id', $work->id)->value('final_crop_path'));
    }

    /** Makes every write of $failingPath on the fake private disk throw, like a full disk. */
    private function failDiskWritesTo(string $failingPath): void
    {
        $fake = Storage::disk('local');
        Storage::set('local', new class($fake->getDriver(), $fake->getAdapter(), $fake->getConfig(), $failingPath) extends FilesystemAdapter
        {
            public function __construct($driver, $adapter, array $config, private readonly string $failingPath)
            {
                parent::__construct($driver, $adapter, $config);
            }

            public function putFileAs($path, $file, $name = null, $options = [])
            {
                if (trim($path, '/').'/'.$name === $this->failingPath) {
                    throw new RuntimeException("disk full while writing {$this->failingPath}");
                }

                return parent::putFileAs($path, $file, $name, $options);
            }
        });
    }
}
