<?php

namespace Tests\Feature\Api;

use App\Jobs\GradeSubmissionPageJob;
use App\Models\Assignment;
use App\Models\Response;
use App\Models\Submission;
use App\Models\SubmissionPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mpdf\Mpdf;
use RuntimeException;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * Hand-ins from files (DESIGN §19.6, §19.9): the student's own submission in
 * the app and the teacher's upload for a student, both into the whole-page
 * path (§19.4). Identity is the token (student) or the route (teacher);
 * late policy, file limits and who may call are checked here. Grading
 * itself is WholePageGradingTest's; the queue is faked.
 *
 * World (ScanFixtures): a ready worksheet assignment with an approved key,
 * student 12 in the teacher's classroom.
 */
class WholePageUploadsTest extends TestCase
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

    private static function photo(string $name = 'page.jpg'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, "\xFF\xD8\xFF\xE0JFIF page ".bin2hex(random_bytes(4)));
    }

    private static function pdf(int $pages, string $name = 'work.pdf'): UploadedFile
    {
        $mpdf = new Mpdf(['tempDir' => storage_path('framework/testing')]);
        for ($i = 1; $i <= $pages; $i++) {
            if ($i > 1) {
                $mpdf->AddPage();
            }
            $mpdf->WriteHTML("<p>page {$i}</p>");
        }

        return UploadedFile::fake()->createWithContent($name, $mpdf->Output('', 'S'));
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function submit(array $files, ?Assignment $assignment = null, ?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->student)->post(
            '/api/v1/student/assignments/'.($assignment ?? $this->assignment)->id.'/submission',
            ['files' => $files],
            ['Accept' => 'application/json'],
        );
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function upload(array $files, ?User $student = null, ?User $as = null, ?Assignment $assignment = null): TestResponse
    {
        return $this->asUser($as ?? $this->teacher)->post(
            '/api/v1/assignments/'.($assignment ?? $this->assignment)->id.'/students/'.($student ?? $this->student)->id.'/pages',
            ['files' => $files],
            ['Accept' => 'application/json'],
        );
    }

    private function listed(?User $as = null): TestResponse
    {
        return $this->asUser($as ?? $this->student)->getJson('/api/v1/student/assignments')->assertOk();
    }

    public function test_a_student_lists_only_the_ready_assignments_of_their_classrooms(): void
    {
        $this->assignment->update(['due_at' => now()->addDays(3)]);
        $soon = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY, 'title' => 'ส่งพรุ่งนี้', 'due_at' => now()->addDay()]);
        $open = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY, 'title' => 'ไม่มีกำหนด', 'due_at' => null]);
        $closedLate = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY, 'title' => 'เลยกำหนด', 'due_at' => now()->subDay(), 'accept_late' => false]);
        Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_DRAFT, 'mode' => Assignment::MODE_FREEFORM, 'title' => 'ร่าง']);
        Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_CLOSED, 'title' => 'ปิดแล้ว']);
        $otherClass = $this->makeClassroom($this->makeTeacher($this->teacher->school), ['name' => 'ห้องอื่น']);
        Assignment::factory()->for_classroom($otherClass)->create(['status' => Assignment::STATUS_READY, 'title' => 'ของห้องอื่น']);

        $data = $this->listed()->json('data');

        $this->assertSame([$closedLate->id, $soon->id, $this->assignment->id, $open->id], array_column($data, 'id'), 'soonest due first, no due date last; draft, closed and other classrooms hidden');
        $this->assertSame(['id', 'title', 'classroom', 'subject_name', 'due_at', 'accept_late', 'can_submit', 'submission_id', 'submitted_at', 'late', 'status'], array_keys($data[0]));
        $this->assertSame([false, false, 'not_submitted', null], [$data[0]['accept_late'], $data[0]['can_submit'], $data[0]['status'], $data[0]['submitted_at']]);
        $this->assertSame([true, 'not_submitted'], [$data[1]['can_submit'], $data[1]['status']]);
        $this->assertSame(['id' => $this->classroom->id, 'name' => $this->classroom->name], $data[2]['classroom']);
    }

    public function test_a_student_hands_in_photos_and_a_pdf_as_themself_and_sees_only_that_it_was_sent(): void
    {
        $response = $this->submit([self::photo(), self::pdf(2)])->assertCreated();

        $submission = Submission::query()->sole();
        $this->assertSame([$this->student->id, $this->assignment->id, false], [$submission->student_id, $submission->assignment_id, $submission->late]);
        $this->assertNotNull($submission->submitted_at);
        $this->assertSame([
            'assignment_id' => $this->assignment->id,
            'submission_id' => $submission->id,
            'submitted_at' => $submission->submitted_at->toIso8601String(),
            'late' => false,
            'status' => 'submitted',
            'files' => 2,
            'pages' => 3,
        ], $response->json('data'), 'no score, state or grading detail for the student');

        $pages = SubmissionPage::query()->orderBy('position')->get();
        $this->assertSame([SubmissionPage::SOURCE_STUDENT_APP, SubmissionPage::SOURCE_STUDENT_APP], $pages->pluck('source')->all());
        $this->assertSame([$this->student->id, $this->student->id], $pages->pluck('uploaded_by')->all());
        $this->assertSame([['image/jpeg', 1], ['application/pdf', 2]], $pages->map(fn ($p) => [$p->mime_type, $p->page_count])->all());
        foreach ($pages as $page) {
            Storage::disk('local')->assertExists($page->file_path);
            $this->assertStringStartsWith("pages/{$this->assignment->school_id}/{$this->assignment->id}/", $page->file_path);
        }

        // Nothing graded yet, so it is read at once: one job per file (§19.4).
        Queue::assertPushed(GradeSubmissionPageJob::class, 2);
        $this->assertSame([SubmissionPage::STATE_GRADING, SubmissionPage::STATE_GRADING], $pages->pluck('state')->all());
        $this->assertSame(Submission::CHANNEL_WHOLE_PAGE, $submission->fresh()->channel);

        $row = $this->listed()->json('data.0');
        $this->assertSame([$submission->id, 'submitted', false], [$row['submission_id'], $row['status'], $row['late']]);
        $this->assertNotNull($row['submitted_at']);
    }

    public function test_after_the_due_time_work_is_marked_late_or_refused(): void
    {
        $this->assignment->update(['due_at' => now()->subHour(), 'accept_late' => true]);
        $this->submit([self::photo()])->assertCreated()->assertJsonPath('data.late', true);
        $this->assertTrue(Submission::query()->sole()->late);
        $this->assertTrue($this->listed()->json('data.0.late'));

        $strict = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_READY, 'due_at' => now()->subMinute(), 'accept_late' => false]);
        $this->submit([self::photo()], $strict)
            ->assertStatus(422)
            ->assertJsonPath('code', 'submission_late');
        $this->assertSame(0, Submission::query()->where('assignment_id', $strict->id)->count(), 'nothing stored');
        $this->assertSame(1, SubmissionPage::query()->count());

        // Before the due time the strict assignment still takes work.
        $strict->update(['due_at' => now()->addMinute()]);
        $this->submit([self::photo()], $strict)->assertCreated()->assertJsonPath('data.late', false);
    }

    public function test_only_ready_assignments_take_work(): void
    {
        $draft = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_DRAFT, 'mode' => Assignment::MODE_FREEFORM]);
        $closed = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_CLOSED]);

        foreach ([$draft, $closed] as $assignment) {
            $this->submit([self::photo()], $assignment)
                ->assertStatus(409)
                ->assertJsonPath('code', 'assignment_not_ready');
        }
        $this->assertSame(0, SubmissionPage::query()->count());
        Queue::assertNothingPushed();
    }

    public function test_a_student_hands_in_only_to_their_own_classroom(): void
    {
        $otherClass = $this->makeClassroom($this->makeTeacher($this->teacher->school));
        $foreign = Assignment::factory()->for_classroom($otherClass)->create(['status' => Assignment::STATUS_READY]);
        $this->submit([self::photo()], $foreign)->assertNotFound()->assertJsonPath('code', 'not_found');

        // A student of another classroom cannot hand in to this one, even with the id.
        $outsider = $this->enrollStudent($otherClass, 3, 'คนนอกห้อง')['student'];
        $this->submit([self::photo()], $this->assignment, $outsider)->assertNotFound();
        $this->assertSame(0, SubmissionPage::query()->count());

        // Teachers use their own endpoint; the student route needs the student ability.
        $this->submit([self::photo()], $this->assignment, $this->teacher)->assertForbidden();
        $this->asUser($this->teacher)->getJson('/api/v1/student/assignments')->assertForbidden();
    }

    public function test_file_limits_are_checked_before_anything_is_stored(): void
    {
        config(['eduvision.submissions.max_file_mb' => 1]);
        $cases = [
            'no file' => [[], 'validation_failed'],
            'six files' => [array_map(fn () => self::photo(), range(1, 6)), 'too_many_pages'],
            'pdf pages count' => [[self::pdf(5), self::photo()], 'too_many_pages'],
            'too large' => [[UploadedFile::fake()->create('big.jpg', 1500, 'image/jpeg')], 'file_too_large'],
            'word' => [[UploadedFile::fake()->createWithContent('work.docx', 'PK word')], 'unsupported_file_type'],
            'other type' => [[UploadedFile::fake()->createWithContent('notes.txt', 'hello')], 'unsupported_file_type'],
            'broken pdf' => [[UploadedFile::fake()->createWithContent('broken.pdf', "%PDF-1.7\n1 0 obj garbage")], 'pdf_unreadable'],
        ];
        foreach ($cases as $label => [$files, $code]) {
            $this->submit($files)->assertStatus(422)->assertJsonPath('code', $code);
            $this->upload($files)->assertStatus(422)->assertJsonPath('code', $code);
            $this->assertSame(0, SubmissionPage::query()->count(), $label);
        }
        $this->assertSame([], Storage::disk('local')->allFiles('pages'));

        // Five pages in all is the limit, not over it.
        $this->submit([self::pdf(4), self::photo()])->assertCreated()->assertJsonPath('data.pages', 5);
    }

    public function test_a_body_over_post_max_size_is_file_too_large(): void
    {
        // PHP drops every file of such a body; ValidatePostSize reads Content-Length.
        $this->asUser($this->student)->call('POST', '/api/v1/student/assignments/'.$this->assignment->id.'/submission', [], [], [], [
            'CONTENT_LENGTH' => (string) (PHP_INT_MAX >> 1),
            'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
            'HTTP_ACCEPT' => 'application/json',
        ])->assertStatus(413)
            ->assertJsonPath('code', 'file_too_large')
            ->assertJsonPath('errors.files.0', 'ไฟล์ที่ส่งรวมกันใหญ่เกินที่ระบบรับได้ ส่งทีละน้อยไฟล์ลงหรือย่อรูปก่อนส่ง');
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_a_storage_failure_leaves_no_empty_submission_behind(): void
    {
        $disk = Mockery::mock(Storage::disk('local'))->makePartial();
        $disk->shouldReceive('put')->andThrow(new RuntimeException('disk full'));
        Storage::set('local', $disk);
        $this->withoutExceptionHandling();

        try {
            $this->submit([self::photo()]);
            $this->fail('the storage error should reach the caller');
        } catch (RuntimeException $e) {
            $this->assertSame('disk full', $e->getMessage());
        }

        $this->assertSame(0, Submission::query()->count(), 'no hand-in is counted for this student');
        $this->assertSame(0, SubmissionPage::query()->count());
    }

    public function test_a_new_hand_in_after_grading_waits_for_the_teacher(): void
    {
        $this->submit([self::photo()])->assertCreated();
        $submission = Submission::query()->sole();
        Response::query()->where('submission_id', $submission->id)->update(['grading_state' => Response::STATE_SCORED, 'ai_score' => 1]);
        Queue::fake();

        $this->submit([self::photo('again.jpg')])->assertCreated();

        Queue::assertNothingPushed();
        $this->assertTrue($submission->fresh()->regrade_pending);
        $this->assertSame(1, SubmissionPage::query()->where('state', SubmissionPage::STATE_STORED)->count());

        // A later hand-in before the teacher's "ตรวจ" replaces the waiting one.
        $this->submit([self::photo('third.jpg')])->assertCreated();
        $this->assertSame(1, SubmissionPage::query()->where('state', SubmissionPage::STATE_STORED)->count());
        $this->assertSame(1, SubmissionPage::query()->where('state', SubmissionPage::STATE_SUPERSEDED)->count());

        $this->asUser($this->teacher)->postJson("/api/v1/submissions/{$submission->id}/grade")->assertStatus(202);
        Queue::assertPushed(GradeSubmissionPageJob::class, 1);
    }

    public function test_a_published_result_shows_as_published(): void
    {
        $this->submit([self::photo()])->assertCreated();
        Submission::query()->update(['status' => Submission::STATUS_PUBLISHED, 'published_at' => now()]);

        $this->assertSame('published', $this->listed()->json('data.0.status'));
    }

    public function test_the_teacher_uploads_a_students_pages_into_the_whole_page_path(): void
    {
        $response = $this->upload([self::pdf(2), self::photo()])->assertCreated();

        $submission = Submission::query()->sole();
        $this->assertSame($this->student->id, $submission->student_id);
        $this->assertSame([$submission->id, $this->student->id, true, false, false], [
            $response->json('data.submission_id'), $response->json('data.student_id'),
            $response->json('data.grading'), $response->json('data.waiting_key'), $response->json('data.regrade_pending'),
        ]);
        $pages = $response->json('data.pages');
        $this->assertSame(['id', 'position', 'mime_type', 'page_count', 'size_bytes', 'state'], array_keys($pages[0]));
        $this->assertSame([[1, 'application/pdf', 2, 'grading'], [2, 'image/jpeg', 1, 'grading']], array_map(fn ($p) => [$p['position'], $p['mime_type'], $p['page_count'], $p['state']], $pages));
        $this->assertSame([SubmissionPage::SOURCE_TEACHER_UPLOAD], SubmissionPage::query()->distinct()->pluck('source')->all());
        $this->assertSame([$this->teacher->id], SubmissionPage::query()->distinct()->pluck('uploaded_by')->all());
        Queue::assertPushed(GradeSubmissionPageJob::class, 2);
    }

    public function test_a_teacher_upload_keeps_the_late_mark_and_ignores_the_late_policy(): void
    {
        $this->assignment->update(['due_at' => now()->subHour(), 'accept_late' => true]);
        $this->submit([self::photo()])->assertCreated()->assertJsonPath('data.late', true);
        $this->assignment->update(['accept_late' => false]);

        $this->upload([self::photo('clearer.jpg')])->assertCreated();

        $this->assertTrue(Submission::query()->sole()->late, "the student's late hand-in stays late");
    }

    public function test_a_teacher_upload_before_the_key_is_approved_waits(): void
    {
        $draft = Assignment::factory()->for_classroom($this->classroom)->create(['status' => Assignment::STATUS_DRAFT, 'mode' => Assignment::MODE_FREEFORM]);

        $this->upload([self::photo()], assignment: $draft)
            ->assertCreated()
            ->assertJsonPath('data.grading', false)
            ->assertJsonPath('data.waiting_key', true)
            ->assertJsonPath('data.pages.0.state', SubmissionPage::STATE_STORED);
        Queue::assertNothingPushed();
    }

    public function test_only_the_classrooms_teacher_uploads_and_only_for_its_students(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $this->upload([self::photo()], as: $colleague)->assertNotFound();
        $this->upload([self::photo()], as: $this->makeTeacher())->assertNotFound();

        $otherClass = $this->makeClassroom($this->teacher);
        $outsider = $this->enrollStudent($otherClass, 1, 'ห้องอื่นของครูคนเดียวกัน')['student'];
        $this->upload([self::photo()], $outsider)->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->upload([self::photo()], $this->teacher)->assertNotFound();

        $this->upload([self::photo()], as: $this->student)->assertForbidden();
        $this->assertSame(0, SubmissionPage::query()->count());
    }
}
