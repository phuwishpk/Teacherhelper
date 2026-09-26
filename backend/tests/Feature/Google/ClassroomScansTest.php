<?php

namespace Tests\Feature\Google;

use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Scans\ScanFixtures;
use Tests\TestCase;

/**
 * POST /scans with meta.source = classroom (DESIGN §18.3, §18.6): the
 * submission must belong to the assignment's courseWork, the spare sheet
 * (QR student_id 0) takes the matched submitter, a QR of another student is
 * accepted by the QR and flagged identity_mismatch while the import row stays
 * the submitter's, and the import row becomes `imported`.
 */
class ClassroomScansTest extends TestCase
{
    use RefreshDatabase;
    use ScanFixtures;

    private User $classmate;

    private ClassroomSubmissionImport $import;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->makeScanWorld();
        $this->classmate = $this->enrollStudent($this->classroom, 13, 'ด.ช. เพื่อน ร่วมห้อง')['student'];

        ClassroomGoogleLink::create(['classroom_id' => $this->classroom->id, 'course_id' => 'c1', 'course_name' => 'x', 'owner_user_id' => $this->teacher->id, 'linked_at' => now()]);
        AssignmentGoogleLink::create(['assignment_id' => $this->assignment->id, 'course_work_id' => 'cw1', 'alternate_link' => 'https://classroom.google.com/x', 'posted_by' => $this->teacher->id, 'posted_at' => now()]);
        $this->import = ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignment->id,
            'google_submission_id' => 'Cg0I-sub_1',
            'google_user_id' => 'g-student',
            'student_id' => null,
            'attachments' => [['drive_file_id' => 'f1', 'title' => 'a.jpg', 'mime_type' => 'image/jpeg']],
            'google_update_time' => '2026-09-20T02:15:00Z',
        ]);
    }

    private function matchSubmitter(User $student): void
    {
        ClassroomStudent::query()->where('classroom_id', $this->classroom->id)->where('student_id', $student->id)->update(['google_user_id' => 'g-student']);
    }

    /** @return array<string, mixed> */
    private function classroomMeta(int $page = 1, ?int $qrStudent = null, string $submissionId = 'Cg0I-sub_1'): array
    {
        return [
            ...$this->metaFor($page, qr: $this->qr($page, studentId: $qrStudent ?? $this->student->id)),
            'source' => 'classroom',
            'google_submission_id' => $submissionId,
        ];
    }

    public function test_a_classroom_scan_is_stored_with_its_source_and_marks_the_import(): void
    {
        $this->matchSubmitter($this->student);

        $res = $this->postScan($this->classroomMeta())->assertCreated();

        $scan = Scan::query()->findOrFail($res->json('scan_id'));
        $this->assertSame(['classroom', 'Cg0I-sub_1'], [$scan->source, $scan->google_submission_id]);
        $this->assertSame($this->student->id, Submission::query()->findOrFail($res->json('submission_id'))->student_id);
        $this->import->refresh();
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, $this->student->id], [$this->import->state, $this->import->student_id]);
        $this->assertFalse(Response::query()->get()->contains(fn (Response $r) => ($r->fuzzy_trace['identity_mismatch'] ?? false) === true));

        // A camera scan keeps the default source and ignores a submission id.
        $camera = $this->postScan([...$this->metaFor(2), 'google_submission_id' => 'Cg0I-sub_1'])->assertCreated();
        $this->assertSame(['camera', null], [Scan::query()->findOrFail($camera->json('scan_id'))->source, Scan::query()->findOrFail($camera->json('scan_id'))->google_submission_id]);
    }

    public function test_the_spare_sheet_is_filed_under_the_matched_submitter(): void
    {
        $this->postScan($this->classroomMeta(qrStudent: 0))
            ->assertStatus(422)
            ->assertJsonPath('code', 'student_unknown');
        $this->assertDatabaseCount('scans', 0);

        $this->matchSubmitter($this->classmate);
        $res = $this->postScan($this->classroomMeta(qrStudent: 0))->assertCreated();
        $this->assertSame($this->classmate->id, Submission::query()->findOrFail($res->json('submission_id'))->student_id);
        $this->assertSame($this->classmate->id, $this->import->refresh()->student_id);

        // From the camera the spare sheet is still refused.
        $this->postScan($this->metaFor(1, qr: $this->qr(1, studentId: 0)))->assertStatus(422)->assertJsonPath('code', 'student_unknown');
    }

    public function test_a_qr_of_another_student_is_accepted_by_the_qr_and_flagged(): void
    {
        $this->matchSubmitter($this->classmate);

        $res = $this->postScan($this->classroomMeta(qrStudent: $this->student->id))->assertCreated();

        $this->assertSame($this->student->id, Submission::query()->findOrFail($res->json('submission_id'))->student_id);
        $responses = Response::query()->where('scan_id', $res->json('scan_id'))->get();
        $this->assertCount(2, $responses);
        foreach ($responses as $response) {
            $this->assertTrue($response->fuzzy_trace['identity_mismatch'] ?? false);
        }
        $this->asUser($this->teacher)->getJson("/api/v1/assignments/{$this->assignment->id}/review-queue")
            ->assertOk()
            ->assertJsonPath('data.0.identity_mismatch', true);

        // The Classroom submission is still the classmate's (the row was synced
        // before the match, so it takes the live roster), never the QR's student:
        // otherwise the grade push would write this student's score onto it.
        $this->import->refresh();
        $this->assertSame([ClassroomSubmissionImport::STATE_IMPORTED, $this->classmate->id], [$this->import->state, $this->import->student_id]);
        $this->assertNull(Submission::query()->where('student_id', $this->classmate->id)->first(), 'the classmate got no submission from this sheet');
    }

    public function test_the_identity_flag_survives_a_confirmed_rescan_of_a_published_page(): void
    {
        $first = $this->postScan($this->metaFor(1))->assertCreated();
        Submission::query()->findOrFail($first->json('submission_id'))->update(['status' => 'published', 'published_at' => now(), 'total_score' => 1]);
        $this->matchSubmitter($this->classmate);

        $pending = $this->postScan($this->classroomMeta())->assertStatus(202)->assertJsonPath('state', 'pending_confirm');
        $this->asUser($this->teacher)->postJson('/api/v1/scans/'.$pending->json('scan_id').'/confirm-replace')->assertOk();

        foreach (Response::query()->where('scan_id', $pending->json('scan_id'))->get() as $response) {
            $this->assertTrue($response->fuzzy_trace['identity_mismatch'] ?? false);
        }
    }

    public function test_the_submission_must_be_a_synced_one_of_this_assignment(): void
    {
        $this->matchSubmitter($this->student);

        $this->postScan($this->classroomMeta(submissionId: 'unknown-submission'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_submission_unknown');

        // A submission of another assignment (another courseWork) does not fit this sheet.
        $other = Assignment::factory()->for_classroom($this->classroom)->create();
        ClassroomSubmissionImport::create(['assignment_id' => $other->id, 'google_submission_id' => 'other-sub', 'google_user_id' => 'g-student', 'attachments' => [], 'google_update_time' => 't']);
        $this->postScan($this->classroomMeta(submissionId: 'other-sub'))
            ->assertStatus(422)
            ->assertJsonPath('code', 'google_submission_unknown');

        $meta = $this->classroomMeta();
        unset($meta['google_submission_id']);
        $this->postScan($meta)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => ['meta.google_submission_id']]);
        $this->postScan([...$this->metaFor(1), 'source' => 'drive'])->assertStatus(422)->assertJsonStructure(['errors' => ['meta.source']]);
        $this->assertDatabaseCount('scans', 0);
        $this->assertSame(ClassroomSubmissionImport::STATE_NEW, $this->import->refresh()->state);
    }
}
