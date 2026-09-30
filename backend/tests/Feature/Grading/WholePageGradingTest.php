<?php

namespace Tests\Feature\Grading;

use App\Domain\Gemini\FakeGeminiClient;
use App\Domain\Gemini\GeminiClient;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Google\GoogleApiException;
use App\Domain\Grading\FeedbackTemplates;
use App\Domain\Grading\WholePageGrader;
use App\Domain\Notifications\Notifier;
use App\Events\SubmissionReopened;
use App\Jobs\FetchClassroomAttachmentsJob;
use App\Jobs\GradeSubmissionPageJob;
use App\Jobs\SyncClassroomRosterJob;
use App\Models\AiCall;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\Response;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Google\GoogleFixtures;
use Tests\Feature\Scans\ScanFixtures;
use Tests\Support\RecordingNotifier;
use Tests\TestCase;

/**
 * Whole-page grading (DESIGN §19.4, §21.4, §21.5): a Classroom hand-in is
 * downloaded on the server with the teacher's token, stored as
 * submission_pages and read with one extract_page call per file; missing or
 * invalid answers are asked again alone; the pages are merged and graded
 * with the fuzzy rules. Google is Http::fake, Gemini the FakeGeminiClient.
 *
 * World (ScanFixtures): Q1 mcq (key B, 1 pt), Q2 short "20" (2 pts), Q3
 * show_work (5 pts), Q4 open (4 pts); student 12 handed in as Google user g-12.
 */
class WholePageGradingTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use ScanFixtures;

    private FakeGeminiClient $gemini;

    /** @var list<array<string, mixed>> studentSubmissions.list */
    private array $turnedIn = [];

    /** @var array<string, array{name: string, mime: string, bytes: string, size?: int}> Drive files by id */
    private array $drive = [];

    private bool $driveDown = false;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->makeScanWorld();
        $this->open->rubricCriteria()->createMany([
            ['position' => 1, 'description' => 'บอกได้ว่าคลอโรฟิลล์สะท้อนแสงสีเขียว', 'points' => 2, 'is_core' => true, 'source' => 'teacher'],
            ['position' => 2, 'description' => 'อธิบายการดูดกลืนแสงสีอื่น', 'points' => 2, 'is_core' => false, 'source' => 'teacher'],
        ]);

        $this->configureGoogle();
        $this->connectGoogle($this->teacher);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => self::COURSE_ID, 'course_name' => 'วิทย์', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        AssignmentGoogleLink::create(['assignment_id' => $this->assignment->id, 'course_work_id' => self::COURSE_WORK_ID, 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        ClassroomStudent::query()->where('student_id', $this->student->id)->update(['google_user_id' => 'g-12']);

        $this->gemini = new FakeGeminiClient('gemini-3.8-flash');
        $this->app->instance(GeminiClient::class, $this->gemini);
        $this->app->instance(Notifier::class, new RecordingNotifier);

        $this->fakeGoogle([
            'classroom.googleapis.com/v1/courses/'.self::COURSE_ID.'/courseWork/'.self::COURSE_WORK_ID.'/studentSubmissions*' => fn () => Http::response(['studentSubmissions' => $this->turnedIn]),
            'www.googleapis.com/drive/v3/files/*' => function (Request $request) {
                if ($this->driveDown) {
                    return Http::response(self::googleError(503, 'UNAVAILABLE', 'Backend Error'), 503);
                }
                $id = rawurldecode((string) preg_replace('#^.*/files/([^?]+).*$#', '$1', $request->url()));
                $file = $this->drive[$id] ?? null;
                if ($file === null) {
                    return Http::response(self::googleError(404, 'NOT_FOUND', 'File not found'), 404);
                }
                if (($request->data()['alt'] ?? null) === 'media' || str_contains($request->url(), 'alt=media')) {
                    return Http::response($file['bytes']);
                }

                return Http::response(['mimeType' => $file['mime'], 'name' => $file['name'], 'size' => (string) ($file['size'] ?? strlen($file['bytes']))]);
            },
        ]);
    }

    /** A "JPEG" whose bytes carry fake markers (FakeGeminiClient reads them like handwriting). */
    private static function jpeg(string $markers = ''): string
    {
        return "\xFF\xD8\xFF\xE0JFIF page ".$markers.' '.bin2hex(random_bytes(4));
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $files  [drive id, name, bytes, mime]
     */
    private function handIn(array $files, string $updateTime = '2026-09-20T02:15:00.000Z', string $userId = 'g-12', bool $late = false): void
    {
        foreach ($files as $f) {
            $this->drive[$f[0]] = ['name' => $f[1], 'bytes' => $f[2], 'mime' => $f[3] ?? 'image/jpeg'];
        }
        $submission = self::turnedIn('sub-12', $userId, array_map(fn (array $f) => [$f[0], $f[1]], $files), $updateTime);
        $submission['late'] = $late;
        $this->turnedIn = [$submission];
    }

    private function sync(): void
    {
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/google-submissions")->assertOk();
    }

    private function mark(string $question, string $markers): void
    {
        $this->{$question}->update(['prompt_text' => $this->{$question}->prompt_text.' '.$markers]);
    }

    private function response(string $question): Response
    {
        return Response::query()->where('question_id', $this->{$question}->id)->sole();
    }

    private function import(): ClassroomSubmissionImport
    {
        return ClassroomSubmissionImport::query()->where('google_submission_id', 'sub-12')->sole();
    }

    public function test_a_classroom_hand_in_is_downloaded_read_in_one_call_and_graded(): void
    {
        $this->mark('mcq', '[fake:correct]');
        $this->mark('short', '[fake:correct]');
        $this->mark('work', '[fake:partial]');
        $this->mark('open', '[fake:correct]');
        $bytes = self::jpeg();
        $this->handIn([['f-1', 'IMG_0001.JPG', $bytes]], late: true);

        $this->sync();

        // The files came through the server with the teacher's token (§19.4).
        $downloads = $this->sentTo('/drive/v3/files/f-1');
        $this->assertCount(2, $downloads, 'metadata, then the bytes');
        $this->assertTrue($downloads[1]->hasHeader('Authorization', 'Bearer '.self::ACCESS_TOKEN));

        $import = $this->import();
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, $this->student->id, null], [$import->state, $import->student_id, $import->last_error]);
        $submission = Submission::query()->sole();
        $this->assertSame([$this->student->id, Submission::CHANNEL_WHOLE_PAGE, true, false, 'needs_review'], [$submission->student_id, $submission->channel, $submission->late, $submission->regrade_pending, $submission->status]);
        $page = SubmissionPage::query()->sole();
        $this->assertSame([SubmissionPage::SOURCE_CLASSROOM, 'image/jpeg', 1, 'sub-12', 'f-1', SubmissionPage::STATE_GRADED], [$page->source, $page->mime_type, $page->page_count, $page->google_submission_id, $page->drive_file_id, $page->state]);
        $this->assertSame("pages/{$this->assignment->school_id}/{$this->assignment->id}/{$page->id}.jpg", $page->file_path);
        $this->assertSame($bytes, Storage::disk('local')->get($page->file_path), 'stored as it came');
        $this->assertSame(hash('sha256', $bytes), $page->sha256);

        // One call for the page with all four questions.
        $pageCalls = array_values(array_filter($this->gemini->requests, fn ($r) => $r->purpose === 'extract_page'));
        $this->assertCount(1, $pageCalls);
        $call = $pageCalls[0];
        $this->assertSame([$bytes], array_map(fn ($i) => $i->data, $call->images));
        $this->assertSame(['image/jpeg', 'high'], [$call->images[0]->mimeType, $call->images[0]->mediaResolution], 'GEMINI_MEDIA_PAGE, high until calibrated (§21.5)');
        $this->assertSame([1, 2, 3, 4], array_keys($call->hints['questions']));
        $this->assertStringContainsString('"question_no": 3', $call->userText);
        $this->assertStringContainsString('a photo of one page', $call->userText);
        $this->assertStringContainsString('Never copy them into your output', $call->systemInstruction);
        foreach ([$this->student->name, $this->classroom->name, $this->teacher->name, 'g-12'] as $secret) {
            $this->assertStringNotContainsString($secret, $call->systemInstruction.$call->userText, 'no name from the database goes to Gemini');
        }

        // Every question graded from the page, pointing at it.
        $mcq = $this->response('mcq');
        $this->assertSame(['scored', 1.0, null, $page->id], [$mcq->grading_state, $mcq->ai_score, $mcq->scan_id, $mcq->submission_page_id]);
        $this->assertSame(['B'], $mcq->extraction['selected_options']);
        $this->assertSame('gemini_page', $mcq->fuzzy_trace['read_by']);
        $short = $this->response('short');
        $this->assertSame([2.0, 'confident'], [$short->ai_score, $short->priority_band]);
        $this->assertEquals(0.0, $short->fuzzy_trace['priority']['inputs']['D'], 'no CNN on this path: no reader disagreement');
        $this->assertSame([160, 60, 230, 940], $short->extraction['answer_box']);
        $this->assertSame(['page_id' => $page->id, 'pages' => 1, 'page_conflict' => false], $short->fuzzy_trace['whole_page']);
        $this->assertSame(Response::EXPLANATION_TEMPLATE, $short->explanation_source, 'full marks: praise from the template');
        $work = $this->response('work');
        $this->assertSame('scored', $work->grading_state);
        $this->assertLessThan(5.0, $work->ai_score);
        $this->assertSame(Response::EXPLANATION_AI, $work->explanation_source);
        $this->assertNotNull($work->explanation);
        $this->assertSame(4.0, $this->response('open')->ai_score);

        // ai_calls: one extract_page (§21.8 labels), one explanation for show_work.
        $calls = AiCall::query()->orderBy('id')->get();
        $this->assertSame(['extract_page', 'explanation'], $calls->pluck('purpose')->all());
        $this->assertSame(['grading_page', 'high', 1, 4, $this->assignment->id], [$calls[0]->feature, $calls[0]->media_resolution, $calls[0]->image_count, $calls[0]->question_count, $calls[0]->assignment_id]);

        // The review screen shows the page and where the answer is.
        $detail = $this->asUser($this->teacher)->getJson("/api/v1/responses/{$short->id}")->assertOk();
        $detail->assertJsonPath('data.page_image_url', "/api/v1/submission-pages/{$page->id}/image")
            ->assertJsonPath('data.page_mime_type', 'image/jpeg')
            ->assertJsonPath('data.answer_box', [160, 60, 230, 940])
            ->assertJsonPath('data.channel', 'whole_page')
            ->assertJsonPath('data.late', true);
        $image = $this->asUser($this->teacher)->get("/api/v1/submission-pages/{$page->id}/image")->assertOk();
        $this->assertSame('image/jpeg', $image->headers->get('Content-Type'));
        $this->assertSame($bytes, $image->streamedContent());
        $this->asUser($this->student)->get("/api/v1/submission-pages/{$page->id}/image")->assertNotFound();
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")
            ->assertOk()
            ->assertJsonPath('meta.submissions.0.channel', 'whole_page')
            ->assertJsonPath('meta.submissions.0.late', true);
    }

    public function test_a_question_the_call_left_out_is_asked_again_alone(): void
    {
        $this->mark('open', '[fake:page-missing] [fake:correct]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:correct]')]]);

        $this->sync();

        $pageCalls = AiCall::query()->where('purpose', 'extract_page')->orderBy('id')->get();
        $this->assertSame([4, 1], $pageCalls->pluck('question_count')->all(), 'the page, then the open question alone');
        $this->assertSame([null, $this->open->id], $pageCalls->pluck('question_id')->all());
        $this->assertSame([4.0, 'scored'], [$this->response('open')->ai_score, $this->response('open')->grading_state]);
    }

    public function test_an_answer_invalid_twice_goes_to_the_teacher(): void
    {
        $this->mark('short', '[fake:invalid]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:correct]')]]);

        $this->sync();

        $short = $this->response('short');
        $this->assertSame(['manual', 'invalid_output', 'check'], [$short->grading_state, $short->manualReason(), $short->priority_band]);
        $this->assertSame(['ok', 'invalid_output'], AiCall::query()->where('purpose', 'extract_page')->orderBy('id')->pluck('status')->all(), 'the retry alone is asked once (§19.4), not retried again by the gateway');
        $this->assertSame('scored', $this->response('mcq')->grading_state, 'the other answers are not held back');
    }

    public function test_a_question_the_retry_leaves_out_again_is_not_found_rather_than_invalid(): void
    {
        $this->mark('short', '[fake:page-missing-always]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:correct]')]]);

        $this->sync();

        $short = $this->response('short');
        $this->assertSame(['manual', WholePageGrader::REASON_ANSWER_NOT_FOUND, 'check'], [$short->grading_state, $short->manualReason(), $short->priority_band]);
        $this->assertSame(['ok', 'ok'], AiCall::query()->where('purpose', 'extract_page')->orderBy('id')->pluck('status')->all());
    }

    public function test_two_merges_of_one_round_pay_for_the_explanations_once(): void
    {
        $this->mark('mcq', '[fake:correct]');
        $this->mark('short', '[fake:correct]');
        $this->mark('work', '[fake:partial]');
        $this->mark('open', '[fake:correct]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $explanations = AiCall::query()->where('purpose', 'explanation')->count();
        $this->assertGreaterThan(0, $explanations);
        $submission = Submission::query()->where('assignment_id', $this->assignment->id)->sole();

        // A second page job that saw no page left `grading` merges again: nothing is queued any more.
        app(WholePageGrader::class)->merge($submission->id, app(GeminiKeyResolver::class)->forTeacher($this->teacher->id));
        $this->assertSame($explanations, AiCall::query()->where('purpose', 'explanation')->count());

        // While another merge of the submission holds the lock, this one waits (and gives up here).
        config(['eduvision.grading.merge_lock_wait_seconds' => 0]);
        $held = Cache::lock('whole-page-merge:'.$submission->id, 60);
        $this->assertTrue($held->get());
        try {
            app(WholePageGrader::class)->merge($submission->id, null);
            $this->fail('the merge should wait for the lock');
        } catch (LockTimeoutException) {
            $this->addToAssertionCount(1);
        } finally {
            $held->release();
        }
    }

    public function test_an_answer_found_on_no_page_must_be_checked(): void
    {
        $this->mark('short', '[fake:not-found]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:correct]')]]);

        $this->sync();

        $short = $this->response('short');
        $this->assertSame(['manual', WholePageGrader::REASON_ANSWER_NOT_FOUND, 'check', 1.0], [$short->grading_state, $short->manualReason(), $short->priority_band, $short->review_priority]);
        $this->assertContains('ครูต้องตรวจข้อนี้เอง: หาคำตอบข้อนี้ในภาพไม่เจอ', $this->asUser($this->teacher)->getJson("/api/v1/responses/{$short->id}")->json('data.why'));
        $this->assertSame(1, AiCall::query()->where('purpose', 'extract_page')->count(), 'found = false is an answer: no retry');
        $this->assertSame('needs_review', Submission::query()->sole()->status);
    }

    public function test_pages_are_merged_and_two_pages_that_disagree_raise_d(): void
    {
        // Page 1 holds Q1–Q3, page 2 Q2 again (written differently) and Q4.
        $this->handIn([
            ['f-1', 'p1.jpg', self::jpeg('[fake:questions=1,2,3] [fake:correct]')],
            ['f-2', 'p2.jpg', self::jpeg('[fake:questions=2,4] [fake:wrong]')],
        ]);

        $this->sync();

        [$p1, $p2] = SubmissionPage::query()->orderBy('position')->get()->all();
        $this->assertSame(2, AiCall::query()->where('purpose', 'extract_page')->count(), 'one call per page');
        $short = $this->response('short');
        $this->assertSame($p1->id, $short->submission_page_id, 'the first page with an answer');
        $this->assertTrue($short->fuzzy_trace['whole_page']['page_conflict']);
        $this->assertEquals(1.0, $short->fuzzy_trace['priority']['inputs']['D'], 'two non-blank answers that differ: D = 1 (§19.4)');
        $this->assertSame('check', $short->priority_band);
        $open = $this->response('open');
        $this->assertSame([$p2->id, false], [$open->submission_page_id, $open->fuzzy_trace['whole_page']['page_conflict']]);
        $this->assertSame($p1->id, $this->response('work')->submission_page_id);
        $this->assertSame(1.0, $this->response('mcq')->ai_score);
    }

    public function test_a_pdf_goes_as_one_part_and_counts_its_pages(): void
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        $mpdf->WriteHTML('<p>หน้า 1</p>');
        $mpdf->AddPage();
        $mpdf->WriteHTML('<p>หน้า 2</p>');
        $pdf = $mpdf->Output('', 'S');
        $this->handIn([['f-pdf', 'งาน.pdf', $pdf, 'application/pdf']]);

        $this->sync();

        $page = SubmissionPage::query()->sole();
        $this->assertSame(['application/pdf', 2, SubmissionPage::STATE_GRADED], [$page->mime_type, $page->page_count, $page->state]);
        $call = array_values(array_filter($this->gemini->requests, fn ($r) => $r->purpose === 'extract_page'))[0];
        $this->assertSame(['application/pdf', 'high'], [$call->images[0]->mimeType, $call->images[0]->mediaResolution], 'a student PDF uses the page level, not GEMINI_MEDIA_DOCUMENT');
        $this->assertStringContainsString('a PDF with 2 pages', $call->userText);
    }

    /**
     * @return array<string, array{0: list<array{0: string, 1: string, 2: string, 3?: string}>, 1: string, 2?: int}>
     */
    public static function unsupportedHandIns(): array
    {
        $jpeg = "\xFF\xD8\xFF\xE0JFIF";

        return [
            'more than 5 pages' => [array_map(fn (int $i) => ["f-{$i}", "p{$i}.jpg", $jpeg.$i], range(1, 6)), 'มากกว่า 5 หน้า'],
            'a Word file' => [[['f-doc', 'งาน.docx', 'PK..', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']], 'ชนิดที่ตรวจไม่ได้'],
            'a Google Doc' => [[['f-gdoc', 'งาน', '', 'application/vnd.google-apps.document']], 'Google Docs/Sheets/Slides'],
            'an unreadable PDF' => [[['f-bad', 'งาน.pdf', "%PDF-1.7\n1 0 obj garbage", 'application/pdf']], 'อ่านไฟล์ PDF "งาน.pdf" ไม่ได้'],
            'a file over 10 MB' => [[['f-big', 'big.jpg', $jpeg]], 'ใหญ่เกิน 10 MB', 11 * 1024 * 1024],
        ];
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3?: string}>  $files
     */
    #[DataProvider('unsupportedHandIns')]
    public function test_files_that_cannot_be_graded_make_the_hand_in_unsupported(array $files, string $reason, ?int $size = null): void
    {
        $this->handIn($files);
        if ($size !== null) {
            $this->drive[$files[0][0]]['size'] = $size;
        }

        $this->sync();

        $import = $this->import();
        $this->assertSame(ClassroomSubmissionImport::STATE_UNSUPPORTED, $import->state);
        $this->assertStringContainsString($reason, (string) $import->last_error);
        $this->assertSame(0, SubmissionPage::query()->count());
        $this->assertSame(0, Submission::query()->count());
        $this->assertSame([], $this->gemini->requests);
        if ($size !== null) {
            $this->assertCount(0, $this->sentTo('alt=media'), 'the size is checked before downloading');
        }
    }

    public function test_an_unmatched_submitter_is_not_fetched(): void
    {
        Queue::fake([SyncClassroomRosterJob::class]);
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]], userId: 'g-unknown');

        $this->sync();

        $this->assertSame([ClassroomSubmissionImport::STATE_NEW, null], [$this->import()->state, $this->import()->student_id]);
        $this->assertCount(0, $this->sentTo('/drive/v3/files/'));
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_google_errors_while_downloading(): void
    {
        Queue::fake([FetchClassroomAttachmentsJob::class]);
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        Queue::assertPushed(FetchClassroomAttachmentsJob::class, 1);
        $fetch = fn () => $this->app->call([new FetchClassroomAttachmentsJob($this->import()->id), 'handle']);

        // The student deleted the file: noted, the row waits for the next sync.
        unset($this->drive['f-1']);
        $fetch();
        $this->assertSame(ClassroomSubmissionImport::STATE_NEW, $this->import()->state);
        $this->assertStringContainsString('ไม่พบไฟล์งานที่ส่งใน Google Drive', (string) $this->import()->last_error);

        // Google unreachable: thrown, so the queue retries the job.
        $this->driveDown = true;
        try {
            $fetch();
            $this->fail('a transient error must reach the queue');
        } catch (GoogleApiException $e) {
            $this->assertTrue($e->isTransient());
        }
        (new FetchClassroomAttachmentsJob($this->import()->id))->failed(null);
        $this->assertStringContainsString('ดึงไฟล์จาก Google ไม่สำเร็จ', (string) $this->import()->last_error);
        $this->assertSame(0, SubmissionPage::query()->count());
    }

    public function test_a_new_hand_in_waits_for_the_teacher_unless_it_answers_a_retake_request(): void
    {
        $this->mark('short', '[fake:correct]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $submission = Submission::query()->sole();
        $firstPage = SubmissionPage::query()->sole();
        $calls = count($this->gemini->requests);

        // The student hands in other photos by themselves: stored, not graded (§19.4).
        $this->handIn([['f-2', 'b.jpg', self::jpeg()]], '2026-09-21T02:15:00.000Z');
        $this->sync();

        $submission->refresh();
        $this->assertTrue($submission->regrade_pending);
        $this->assertSame('needs_review', $submission->status);
        $this->assertSame(ClassroomSubmissionImport::STATE_IMPORTED, $this->import()->state);
        $this->assertSame([SubmissionPage::STATE_GRADED, SubmissionPage::STATE_STORED], SubmissionPage::query()->orderBy('id')->pluck('state')->all());
        $this->assertCount($calls, $this->gemini->requests, 'no Gemini call before the teacher presses "ตรวจ"');
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")
            ->assertJsonPath('meta.submissions.0.regrade_pending', true);

        // "ตรวจ": the stored page becomes the round, the old one is superseded.
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")
            ->assertStatus(202)
            ->assertJsonPath('data.regrade_pending', false)
            ->assertJsonPath('data.pages', 1);
        $newPage = SubmissionPage::query()->where('drive_file_id', 'f-2')->sole();
        $this->assertSame([SubmissionPage::STATE_SUPERSEDED, SubmissionPage::STATE_GRADED], [$firstPage->refresh()->state, $newPage->state]);
        $this->assertSame($newPage->id, $this->response('short')->submission_page_id);
        $this->assertSame(4, ScoreEvent::query()->where('action', ScoreEvent::ACTION_RESCAN)->count(), 'every replaced score is logged');
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")
            ->assertStatus(409)
            ->assertJsonPath('code', 'nothing_to_grade');

        // Sent back for a retake, then handed in again: graded at once.
        $import = $this->import();
        $import->forceFill(['state' => ClassroomSubmissionImport::STATE_RETURNED_FOR_RETAKE, 'retake_reason' => 'รูปเบลอ'])->save();
        $this->handIn([['f-3', 'c.jpg', self::jpeg()]], '2026-09-22T02:15:00.000Z');
        $this->sync();

        $this->assertFalse($submission->refresh()->regrade_pending);
        $this->assertSame(SubmissionPage::STATE_GRADED, SubmissionPage::query()->where('drive_file_id', 'f-3')->sole()->state);
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, null], [$this->import()->state, $this->import()->retake_reason]);
    }

    public function test_grading_a_new_hand_in_of_a_published_submission_reopens_it(): void
    {
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $submission = Submission::query()->sole();
        Response::query()->update(['reviewed_at' => now(), 'reviewed_by' => $this->teacher->id]);
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now(), 'published_by' => $this->teacher->id])->save();

        $this->handIn([['f-2', 'b.jpg', self::jpeg()]], '2026-09-21T02:15:00.000Z');
        $this->sync();
        $this->assertSame([Submission::STATUS_PUBLISHED, true], [$submission->refresh()->status, $submission->regrade_pending], 'published results stay until the teacher decides');

        Event::fake([SubmissionReopened::class]);
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")->assertStatus(202);

        $submission->refresh();
        $this->assertSame(['needs_review', null], [$submission->status, $submission->published_at]);
        Event::assertDispatched(SubmissionReopened::class, fn (SubmissionReopened $e) => $e->submissionId === $submission->id);
    }

    public function test_a_gemini_outage_retries_the_page_then_leaves_it_to_the_teacher(): void
    {
        Queue::fake([GradeSubmissionPageJob::class]);
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:error]')]]);
        $this->sync();
        $page = SubmissionPage::query()->sole();
        $this->assertSame([SubmissionPage::STATE_GRADING, 'grading'], [$page->state, Submission::query()->sole()->status]);

        $job = (new GradeSubmissionPageJob($page->id))->withFakeQueueInteractions();
        $this->app->call([$job, 'handle']);
        $job->assertReleased(60);
        $this->assertSame(SubmissionPage::STATE_GRADING, $page->refresh()->state);

        $job = (new GradeSubmissionPageJob($page->id))->withFakeQueueInteractions();
        $job->job->attempts = 3;
        $this->app->call([$job, 'handle']);
        $job->assertNotReleased();

        $this->assertSame([SubmissionPage::STATE_FAILED, 'error'], [$page->refresh()->state, $page->result['status']]);
        $short = $this->response('short');
        $this->assertSame(['manual', 'ai_error'], [$short->grading_state, $short->manualReason()]);
        $this->assertSame('needs_review', Submission::query()->sole()->status);
    }

    public function test_without_a_key_the_answers_wait_and_are_read_again_once_one_is_added(): void
    {
        config(['services.gemini.api_key' => null]);
        $this->handIn([['f-1', 'a.jpg', self::jpeg('[fake:correct]')]]);
        $this->sync();

        $short = $this->response('short');
        $this->assertSame(['manual', 'ai_key_missing'], [$short->grading_state, $short->manualReason()]);
        $this->assertSame([], $this->gemini->requests);

        config(['services.gemini.api_key' => 'testing-server-gemini-key-not-real']);
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/requeue-missing-key")
            ->assertStatus(202)
            ->assertJsonPath('data.requeued', 4);

        $this->assertSame('scored', $this->response('short')->grading_state);
        $this->assertSame(SubmissionPage::STATE_GRADED, SubmissionPage::query()->sole()->state);
    }

    public function test_the_teacher_edits_the_explanation_and_gemini_s_text_is_kept(): void
    {
        $this->mark('work', '[fake:partial]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $work = $this->response('work');
        $original = (string) $work->explanation;

        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$work->id}", [
            'final_score' => $work->ai_score,
            'final_understanding' => $work->ai_understanding,
            'explanation' => 'ครูอธิบายเอง: ตรวจการคูณในบรรทัดที่ 2 อีกครั้ง',
        ])->assertOk()
            ->assertJsonPath('data.explanation', 'ครูอธิบายเอง: ตรวจการคูณในบรรทัดที่ 2 อีกครั้ง')
            ->assertJsonPath('data.ai_explanation', $original)
            ->assertJsonPath('data.explanation_source', 'teacher');

        // A second edit keeps the first original, not the teacher's earlier text.
        $this->asUser($this->teacher)->patchJson("/api/v1/responses/{$work->id}", [
            'final_score' => $work->ai_score,
            'final_understanding' => $work->ai_understanding,
            'explanation' => 'ข้อความใหม่',
        ])->assertOk()->assertJsonPath('data.ai_explanation', $original);

        // Publishing: the student sees the teacher's text.
        $work->refresh();
        $this->assertSame(['ข้อความใหม่', $original, true], [$work->explanation, $work->ai_explanation, $work->explanation_edited]);
    }

    public function test_a_published_page_is_the_student_s_to_see_and_retention_removes_the_files(): void
    {
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $submission = Submission::query()->sole();
        $page = SubmissionPage::query()->sole();
        $submission->forceFill(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()])->save();

        $this->asUser($this->student)->get("/api/v1/submission-pages/{$page->id}/image")->assertOk();
        $other = $this->enrollStudent($this->classroom, 30, 'คนอื่น')['student'];
        $this->asUser($other)->get("/api/v1/submission-pages/{$page->id}/image")->assertNotFound();
        $this->asUser($this->student)->getJson("/api/v1/student/results/{$submission->id}")
            ->assertOk()
            ->assertJsonPath('data.responses.0.page_image_url', "/api/v1/submission-pages/{$page->id}/image");

        // Kept past publishing (it is the only evidence), deleted after crop_retention_until.
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        Storage::disk('local')->assertExists($page->file_path);
        // The school year ended yesterday; the page came in before that.
        $page->forceFill(['received_at' => now()->subDays(3)])->save();
        $this->assignment->school->forceFill(['crop_retention_until' => now()->subDay()->toDateString()])->save();
        $this->artisan('eduvision:purge-images')->assertSuccessful();
        Storage::disk('local')->assertMissing((string) $page->file_path);
        $this->assertNull($page->refresh()->file_path);
        $this->asUser($this->teacher)->get("/api/v1/submission-pages/{$page->id}/image")->assertStatus(410)->assertJsonPath('code', 'image_purged');
    }

    public function test_a_superseded_page_file_goes_in_the_next_purge(): void
    {
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();
        $this->handIn([['f-2', 'b.jpg', self::jpeg()]], '2026-09-21T02:15:00.000Z');
        $this->sync();
        $this->asUser($this->teacher)->postJson('/api/v1/submissions/'.Submission::query()->sole()->id.'/grade')->assertStatus(202);
        $old = SubmissionPage::query()->where('drive_file_id', 'f-1')->sole();
        $new = SubmissionPage::query()->where('drive_file_id', 'f-2')->sole();
        $oldPath = (string) $old->file_path;

        $this->artisan('eduvision:purge-images')->assertSuccessful();

        Storage::disk('local')->assertMissing($oldPath);
        Storage::disk('local')->assertExists((string) $new->file_path);
        $this->assertNull($old->refresh()->file_path);
    }

    public function test_nothing_is_graded_before_the_answer_key_is_approved(): void
    {
        // A freeform assignment whose key the teacher has not approved yet (§19.5).
        $this->assignment->forceFill(['mode' => Assignment::MODE_FREEFORM, 'status' => Assignment::STATUS_DRAFT, 'current_layout_version' => null, 'key_approved_at' => null])->save();
        $this->mark('short', '[fake:correct]');
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();

        $this->assertSame(ClassroomSubmissionImport::STATE_WAITING_KEY, $this->import()->state);
        $page = SubmissionPage::query()->sole();
        $this->assertSame(SubmissionPage::STATE_STORED, $page->state);
        Storage::disk('local')->assertExists((string) $page->file_path);
        $submission = Submission::query()->sole();
        $this->assertFalse($submission->regrade_pending);
        $this->assertSame(0, Response::query()->count(), 'not in the review queue');
        $this->assertSame([], array_values(array_filter($this->gemini->requests, fn ($r) => $r->purpose === 'extract_page')));
        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")
            ->assertStatus(409)
            ->assertJsonPath('code', 'answer_key_not_approved');

        // Approval releases it: graded from the stored file, nothing downloaded again.
        $downloads = count($this->sentTo('/drive/v3/files/f-1'));
        $this->asUser($this->teacher)->postJson("/api/v1/assignments/{$this->assignment->id}/answer-key/approve")
            ->assertOk()
            ->assertJsonPath('data.status', Assignment::STATUS_READY)
            ->assertJsonPath('data.key_complete', true);

        $this->assertSame(SubmissionPage::STATE_GRADED, $page->refresh()->state);
        $this->assertSame([Response::STATE_SCORED, 2.0], [$this->response('short')->grading_state, $this->response('short')->ai_score]);
        $this->assertSame(ClassroomSubmissionImport::STATE_IMPORTED, $this->import()->state);
        $this->assertCount($downloads, $this->sentTo('/drive/v3/files/f-1'));
    }

    public function test_a_score_only_assignment_never_asks_gemini_for_an_explanation(): void
    {
        $this->assignment->forceFill(['score_only' => true])->save();
        $this->mark('short', '[fake:wrong]');
        $this->mark('work', '[fake:partial]');
        $this->mark('open', '[fake:correct]');
        $this->open->update(['model_answer' => 'คลอโรฟิลล์ดูดกลืนแสงสีแดงและน้ำเงิน จึงสะท้อนแสงสีเขียว']);
        $this->handIn([['f-1', 'a.jpg', self::jpeg()]]);
        $this->sync();

        foreach (['short', 'work'] as $question) {
            $response = $this->response($question);
            $this->assertLessThan((float) $this->{$question}->max_points, (float) $response->ai_score);
            $this->assertSame([FeedbackTemplates::SCORE_ONLY, Response::EXPLANATION_TEMPLATE], [$response->explanation, $response->explanation_source]);
        }
        $this->assertSame(['extract_page'], AiCall::query()->pluck('purpose')->all(), 'no explanation call (§21.7)');

        // The teacher's model answer goes to the page read as a reference (§19.5).
        $page = array_values(array_filter($this->gemini->requests, fn ($r) => $r->purpose === 'extract_page'))[0];
        $this->assertStringContainsString('"model_answer": "คลอโรฟิลล์ดูดกลืนแสงสีแดงและน้ำเงิน จึงสะท้อนแสงสีเขียว"', $page->userText);
    }
}
