<?php

namespace Tests\Feature\Accounts;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Accounts\AccountPurge;
use App\Domain\Training\TrainingSamples;
use App\Filament\Resources\Users\AccountDeletionActions;
use App\Models\Assignment;
use App\Models\AuditLog;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\Course;
use App\Models\Mastery;
use App\Models\ScoreEvent;
use App\Models\SkillObservation;
use App\Models\SourceDocument;
use App\Models\Submission;
use App\Models\TeacherApiKey;
use App\Models\TrainingSample;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Google\GoogleFixtures;
use Tests\Feature\Security\SecurityWorld;
use Tests\TestCase;

/**
 * DESIGN §31.3: 30 days after an admin deleted an account, the daily
 * eduvision:purge-images removes its personal data for good. The security
 * world gives teacher A a classroom holding a bit of everything (scans,
 * answers, appeals, imports, conflicts, an exam with figures, the gradebook,
 * attendance, posts with a file, a Google connection, documents).
 */
class AccountPurgeTest extends TestCase
{
    use GoogleFixtures;
    use RefreshDatabase;
    use SecurityWorld;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeSecurityWorld();
        $this->configureGoogle();
        $this->fakeGoogle();
    }

    private function deleteAndAge(User $user, int $days = 31): void
    {
        app(AccountDeletion::class)->delete($this->admin, $user);
        $this->travel($days)->days();
    }

    /** The rows the security world does not make: learning data, a training copy, a key, a seat elsewhere. */
    private function moreTeacherData(): array
    {
        $disk = Storage::disk('local');
        SkillObservation::create(['student_id' => $this->studentA->id, 'skill_id' => $this->skillA->id, 'source' => SkillObservation::SOURCE_HOMEWORK, 'response_id' => $this->responseA->id, 'score_ratio' => 0.5, 'observed_at' => now()]);
        Mastery::create(['student_id' => $this->studentA->id, 'skill_id' => $this->skillA->id, 'value' => 0.5, 'n_obs' => 1]);
        ScoreEvent::create(['response_id' => $this->responseA->id, 'actor' => ScoreEvent::ACTOR_TEACHER, 'actor_user_id' => $this->teacherA->id, 'action' => ScoreEvent::ACTION_OVERRIDE, 'old_score' => 0, 'new_score' => 1]);
        $training = TrainingSample::create([
            'school_id' => $this->schoolA->id, 'source' => TrainingSample::SOURCE_TEACHER_CORRECTION, 'crop_path' => TrainingSamples::path($this->schoolA->id, $this->responseA->id),
            'label' => '21', 'response_id' => $this->responseA->id,
        ]);
        $disk->put($training->crop_path, 'webp');
        TeacherApiKey::query()->create(['user_id' => $this->teacherA->id, 'provider' => 'gemini', 'encrypted_key' => 'TESTTeacherOwnKey000000000000000000099', 'key_last4' => '0099']);
        DB::table('teacher_indicators')->insert(['user_id' => $this->teacherA->id, 'skill_id' => $this->teacherSkillA->id, 'created_at' => now()]);
        // Teacher A co-teaches classroom B, which stays.
        ClassroomTeacher::create(['classroom_id' => $this->classroomB->id, 'email' => (string) $this->teacherA->email, 'teacher_id' => $this->teacherA->id, 'invited_by' => $this->teacherB->id, 'status' => 'accepted', 'decided_at' => now()]);
        // Another teacher uploaded the same exam photo: the file is shared and stays.
        $shared = SourceDocument::create([...$this->examDocumentA->only(['school_id', 'sha256', 'original_name', 'mime_type', 'size_bytes', 'page_count', 'file_path']), 'uploaded_by' => $this->teacherA2->id]);

        return ['training' => $training->crop_path, 'shared' => $shared];
    }

    public function test_a_teachers_classrooms_files_and_person_go_after_30_days(): void
    {
        $extra = $this->moreTeacherData();
        $disk = Storage::disk('local');
        $files = [
            'print' => $this->printA->file_path,
            'cards' => $this->cardPrintA->file_path,
            'scan page' => $this->scanA->page_image_path,
            'crop' => $this->responseA->crop_path,
            'whole page' => $this->pageA->file_path,
            'training' => $extra['training'],
            'post file' => $this->classPostFileA->path,
            'exam figure' => $this->examQuestionA->prompt_image_path,
        ];
        foreach ($files as $what => $path) {
            $this->assertTrue($disk->exists($path), "{$what} exists before");
        }
        // Keep the 30-day document retention (§20) out of the way: this checks the shared-file rule.
        config(['eduvision.documents.retention_days' => 365]);
        $classroomA = $this->classroomA->id;
        $assignmentIds = Assignment::query()->where('classroom_id', $classroomA)->pluck('id')->all();
        $submissionCount = Submission::query()->whereIn('assignment_id', $assignmentIds)->count();
        $this->assertGreaterThanOrEqual(2, $submissionCount);

        $this->deleteAndAge($this->teacherA);
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('Deleted accounts purged (30 days over): 0 students, 1 teachers')->assertSuccessful();

        // The classroom and its whole tree.
        $this->assertNull(Classroom::query()->find($classroomA));
        $this->assertSame(0, Assignment::query()->whereIn('id', $assignmentIds)->count());
        $this->assertSame(0, Submission::query()->whereIn('assignment_id', $assignmentIds)->count());
        foreach (['responses' => 'submission_id', 'scans' => 'submission_id', 'submission_pages' => 'submission_id'] as $table => $column) {
            $this->assertSame(0, DB::table($table)->whereIn($column, [$this->submissionA->id, $this->submissionA2->id])->count(), $table);
        }
        foreach (['appeals', 'score_events', 'skill_observations', 'training_samples'] as $table) {
            $this->assertSame(0, DB::table($table)->whereIn('response_id', [$this->responseA->id, $this->responseA2->id])->count(), $table);
        }
        $this->assertSame(0, DB::table('classroom_submission_imports')->whereIn('assignment_id', $assignmentIds)->count());
        $this->assertSame(0, DB::table('gradebook_entries')->where('classroom_id', $classroomA)->count());
        $this->assertSame(0, DB::table('attendance_sessions')->where('classroom_id', $classroomA)->count());
        foreach ($files as $what => $path) {
            $this->assertFalse($disk->exists($path), "{$what} deleted");
        }
        // Mastery of the student recomputed without the answers that went.
        $this->assertNull(Mastery::query()->where('student_id', $this->studentA->id)->where('skill_id', $this->skillA->id)->first());

        // The teacher's own things.
        $teacher = $this->teacherA->fresh();
        $this->assertSame(AccountPurge::TEACHER_NAME, $teacher->name);
        $this->assertNull($teacher->email);
        $this->assertNull($teacher->school_name);
        $this->assertNotNull($teacher->deleted_at);
        foreach (['google_accounts', 'teacher_api_keys', 'teacher_indicators', 'assistant_conversations', 'device_tokens', 'personal_access_tokens'] as $table) {
            $column = $table === 'personal_access_tokens' ? 'tokenable_id' : 'user_id';
            $this->assertSame(0, DB::table($table)->where($column, $teacher->id)->count(), $table);
        }
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'oauth2.googleapis.com/revoke'));
        $this->assertSame(0, DB::table('classroom_teachers')->where('teacher_id', $teacher->id)->count());
        $this->assertSame(0, DB::table('class_post_files')->where('owner_id', $teacher->id)->count());
        $this->assertSame(0, DB::table('classroom_google_links')->where('owner_user_id', $teacher->id)->count());
        $this->assertSame(0, SourceDocument::query()->where('uploaded_by', $teacher->id)->count());
        $this->assertTrue($disk->exists($extra['shared']->file_path), 'a document file another teacher shares stays');

        // What other teachers may share stays, under "ครูที่ถูกลบ".
        $this->assertNotNull(Course::query()->find($this->courseA->id));
        $this->assertNotNull($this->teacherSkillA->fresh());
        $this->assertNotNull(Classroom::query()->find($this->classroomB->id));
        // Students keep their accounts.
        $this->assertSame('นักเรียน ก หนึ่ง', $this->studentA->fresh()->name);

        $audit = AuditLog::query()->where('action', AuditLog::ACCOUNT_PURGE)->sole();
        $this->assertSame($teacher->id, $audit->target_user_id);
        $this->assertSame($this->admin->id, $audit->actor_id);
        $this->assertSame(1, $audit->meta['classrooms']);
        $this->assertSame($submissionCount, $audit->meta['submissions']);
        $this->assertSame(count($assignmentIds), $audit->meta['assignments']);

        // Once only.
        $this->assertSame(0, AccountPurge::due()->count());
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('0 students, 0 teachers')->assertSuccessful();
        $this->assertSame(1, AuditLog::query()->where('action', AuditLog::ACCOUNT_PURGE)->count());
    }

    public function test_a_students_person_and_images_go_but_their_scores_stay(): void
    {
        $disk = Storage::disk('local');
        $crop = $this->responseA2->crop_path;
        $page = $this->scanA2->page_image_path;
        $this->studentA2->createToken('phone');

        $this->deleteAndAge($this->studentA2);
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('1 students, 0 teachers')->assertSuccessful();

        $student = $this->studentA2->fresh();
        $this->assertSame(AccountPurge::STUDENT_NAME, $student->name);
        $this->assertNull($student->username);
        $this->assertNull($student->student_code);
        $this->assertNull($student->email);
        $this->assertSame(0, DB::table('student_credentials')->where('student_id', $student->id)->count());
        $this->assertSame(0, $student->tokens()->count());

        // Score rows stay, so class averages do not move; the images go.
        $submission = $this->submissionA2->fresh();
        $this->assertSame(1.0, (float) $submission->total_score);
        $this->assertNull($this->responseA2->fresh()->crop_path);
        $this->assertNull($this->scanA2->fresh()->page_image_path);
        $this->assertFalse($disk->exists($crop));
        $this->assertFalse($disk->exists($page));
        $this->assertNotNull($this->appealA2->fresh());
        $this->assertSame(1, DB::table('classroom_students')->where('student_id', $student->id)->count());

        // The classroom, its teacher and the other student are untouched.
        $this->assertNotNull($this->classroomA->fresh());
        $this->assertSame('ครูเจ้าของ', $this->teacherA->fresh()->name);
        $this->assertTrue($disk->exists($this->responseA->crop_path));
    }

    public function test_nothing_happens_before_30_days_and_restore_ends_with_them(): void
    {
        $this->deleteAndAge($this->studentA2, days: 29);
        $this->assertSame(0, AccountPurge::due()->count());
        $this->assertTrue(Gate::forUser($this->admin)->allows('restoreAccount', $this->studentA2->fresh()));
        $this->assertStringStartsWith('ลบแล้ว (กู้คืนได้ถึง', AccountDeletionActions::statusLabel($this->studentA2->fresh()));
        $this->artisan('eduvision:purge-images')->expectsOutputToContain('0 students, 0 teachers');
        $this->assertSame('นักเรียน ก สอง', $this->studentA2->fresh()->name);

        // Day 31, before the daily task ran: no restore, the status says it is final.
        $this->travel(2)->days();
        $student = $this->studentA2->fresh();
        $this->assertFalse(Gate::forUser($this->admin)->allows('restoreAccount', $student));
        $this->assertSame('ลบถาวรแล้ว', AccountDeletionActions::statusLabel($student));
        app(AccountDeletion::class)->restore($this->admin, $student);
        $this->assertNotNull($student->fresh()->deleted_at, 'restore() refuses after the 30 days too');
        $this->assertSame(1, AccountPurge::due()->count());
    }

    public function test_purge_refuses_an_account_that_is_not_due(): void
    {
        $this->expectException(\LogicException::class);
        app(AccountPurge::class)->purge($this->studentA);
    }

    public function test_one_failing_account_does_not_stop_the_others(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('uses a SQLite trigger to make one purge fail');
        }
        app(AccountDeletion::class)->delete($this->admin, $this->studentA2);
        app(AccountDeletion::class)->delete($this->admin, $this->studentB);
        $this->travel(31)->days();
        DB::unprepared("CREATE TRIGGER purge_fails BEFORE UPDATE OF name ON users WHEN NEW.id = {$this->studentA2->id} BEGIN SELECT RAISE(ABORT, 'disk on fire'); END");
        Log::spy();

        $this->assertSame(['students' => 1, 'teachers' => 0, 'failed' => 1], app(AccountPurge::class)->purgeDue());

        $this->assertSame(AccountPurge::STUDENT_NAME, $this->studentB->fresh()->name);
        $this->assertSame('นักเรียน ก สอง', $this->studentA2->fresh()->name);
        Log::shouldHaveReceived('error')->withArgs(fn (string $m, array $c) => $m === 'account.purge_failed' && $c['user_id'] === $this->studentA2->id)->once();
        // Not marked done, so the next day tries again.
        $this->assertSame([$this->studentA2->id], AccountPurge::due()->pluck('id')->all());
    }
}
