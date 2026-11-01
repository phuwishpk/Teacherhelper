<?php

namespace Tests\Feature\Firestore;

use App\Domain\Accounts\AccountDeletion;
use App\Domain\Firestore\FirestoreUnavailable;
use App\Domain\Firestore\LiveRecords;
use App\Domain\Students\StudentMerger;
use App\Jobs\MirrorClassroomRecordsJob;
use App\Jobs\MirrorRecordJob;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\GradebookEntry;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use App\Models\User;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\RecordingLiveStore;
use Tests\TestCase;

/**
 * Student records in Firestore (DESIGN §38.10): names, numbers, codes, scores
 * and answers are copied to classrooms/{id}/… only behind their own switch,
 * follow every change, and leave nothing behind when a student, an account,
 * an assignment or a classroom goes.
 */
class LiveRecordsTest extends TestCase
{
    use FirestoreFixtures;
    use RefreshDatabase;

    private const DOCS = 'https://firestore.googleapis.com/v1/projects/eduvision-test/databases/(default)/documents';

    private User $teacher;

    private Classroom $classroom;

    private Assignment $assignment;

    private User $student;

    private User $classmate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeServiceAccountFile();
        $this->enableFirestore(true);
        config(['services.firebase.firestore_records' => true]);
        Queue::fake(); // Jobs run only where a test calls handle().
        Cache::flush();
        $this->teacher = $this->makeTeacher();
        $this->classroom = $this->makeClassroom($this->teacher);
        $this->student = $this->enrollStudent($this->classroom, 1, 'เด็กชายเอ')['student'];
        $this->classmate = $this->enrollStudent($this->classroom, 2, 'เด็กหญิงบี')['student'];
        $this->student->forceFill(['student_code' => '12345'])->save();
        $this->assignment = Assignment::factory()->for_classroom($this->classroom)->create(['title' => 'เศษส่วน']);
        Cache::flush();
    }

    protected function tearDown(): void
    {
        $this->removeServiceAccountFile();
        parent::tearDown();
    }

    private function submission(?User $student = null, array $attributes = []): Submission
    {
        return Submission::create($attributes + [
            'assignment_id' => $this->assignment->id,
            'student_id' => ($student ?? $this->student)->id,
            'status' => Submission::STATUS_NEEDS_REVIEW,
        ]);
    }

    /** @param  array<string, mixed>  $values */
    private function answer(Submission $submission, Question $question, array $values): Response
    {
        $response = Response::create(['submission_id' => $submission->id, 'question_id' => $question->id]);
        $response->forceFill($values + ['grading_state' => Response::STATE_SCORED])->save();

        return $response;
    }

    private function question(string $state = ''): Question
    {
        $factory = Question::factory();

        return ($state === '' ? $factory : $factory->{$state}())->create(['assignment_id' => $this->assignment->id]);
    }

    /** @return array<string, mixed> */
    private function document(string $collection, int $id): array
    {
        $store = new RecordingLiveStore;
        (new MirrorRecordJob($collection, $id, $this->classroom->id))->handle($store);
        $this->assertCount(1, $store->ops);
        $this->assertSame(LiveRecords::path($this->classroom->id, $collection, $id), $store->ops[0]['path']);
        $this->assertArrayHasKey('fields', $store->ops[0], 'the copy was removed, not written');

        return $store->ops[0]['fields'];
    }

    private function assertRemoved(string $collection, int $id): void
    {
        $store = new RecordingLiveStore;
        (new MirrorRecordJob($collection, $id, $this->classroom->id))->handle($store);
        $this->assertSame([[
            'path' => LiveRecords::path($this->classroom->id, $collection, $id),
            'delete' => true,
        ]], $store->ops);
    }

    /** Runs $change with an empty queue and released unique locks. */
    private function afterChange(Closure $change): void
    {
        Cache::flush();
        Queue::fake();
        $change();
    }

    private function assertRosterQueued(Closure $change, ?int $classroomId = null): void
    {
        $this->afterChange($change);
        Queue::assertPushed(MirrorClassroomRecordsJob::class, fn (MirrorClassroomRecordsJob $job) => $job->rosterOnly
            && $job->classroomId === ($classroomId ?? $this->classroom->id));
    }

    private function assertClassroomQueued(Closure $change, ?int $classroomId = null): void
    {
        $this->afterChange($change);
        Queue::assertPushed(MirrorClassroomRecordsJob::class, fn (MirrorClassroomRecordsJob $job) => ! $job->rosterOnly
            && $job->classroomId === ($classroomId ?? $this->classroom->id));
    }

    /**
     * @param  array<string, list<string>>  $existing  document ids already in Firestore, by collection
     */
    private function fakeFirestore(array $existing = [], int $commitStatus = 200): void
    {
        $base = self::DOCS.'/'.LiveRecords::classroomPath($this->classroom->id);
        $fakes = [
            self::TOKEN_URI => Http::response(['access_token' => 'at', 'expires_in' => 3600]),
            self::COMMIT_URL => Http::response(['writeResults' => []], $commitStatus),
            $base.':listCollectionIds' => Http::response(['collectionIds' => array_keys($existing)]),
        ];
        foreach ([LiveRecords::STUDENTS, LiveRecords::SUBMISSIONS, LiveRecords::GRADEBOOK] as $collection) {
            $path = 'projects/eduvision-test/databases/(default)/documents/'.LiveRecords::collectionPath($this->classroom->id, $collection);
            $fakes[$base.'/'.$collection.'*'] = Http::sequence()
                ->push(['documents' => array_map(fn ($id) => ['name' => $path.'/'.$id], $existing[$collection] ?? [])])
                ->whenEmpty(Http::response([]));
        }
        Http::fake($fakes);
    }

    /** @return array{written: list<string>, deleted: list<string>, fields: array<string, array<string, mixed>>} */
    private function commits(): array
    {
        $out = ['written' => [], 'deleted' => [], 'fields' => []];
        $prefix = 'projects/eduvision-test/databases/(default)/documents/';
        foreach (Http::recorded() as [$request]) {
            if (! str_ends_with($request->url(), ':commit')) {
                continue;
            }
            foreach ($request->data()['writes'] as $write) {
                if (isset($write['delete'])) {
                    $out['deleted'][] = substr($write['delete'], strlen($prefix));
                } else {
                    $path = substr($write['update']['name'], strlen($prefix));
                    $out['written'][] = $path;
                    $out['fields'][$path] = $write['update']['fields'];
                }
            }
        }

        return $out;
    }

    public function test_nothing_moves_unless_both_switches_are_on(): void
    {
        foreach ([[true, false], [false, true]] as [$firestore, $records]) {
            $this->enableFirestore($firestore);
            config(['services.firebase.firestore_records' => $records]);
            $this->afterChange(function () {
                $submission = $this->submission();
                $this->answer($submission, $this->question(), ['ai_score' => 1]);
                GradebookEntry::create(['classroom_id' => $this->classroom->id, 'student_id' => $this->classmate->id, 'assignment_id' => $this->assignment->id, 'score' => 3, 'updated_by' => $this->teacher->id]);
                $this->enrollStudent($this->classroom, 3);
                $this->student->forceFill(['name' => 'ชื่อใหม่'])->save();
                $this->assignment->forceFill(['title' => 'ชื่อใหม่'])->save();
                $this->makeClassroom($this->teacher)->delete();
                $this->submission($this->classmate)->delete();
                app(AccountDeletion::class)->delete($this->makeAdmin(), $this->classmate);
            });
            Queue::assertNotPushed(MirrorRecordJob::class);
            Queue::assertNotPushed(MirrorClassroomRecordsJob::class);

            // Reset what this round changed for the next one.
            $this->classmate->forceFill(['deleted_at' => null])->save();
            GradebookEntry::query()->delete();
            Response::query()->delete();
            Submission::query()->delete();
            $this->classroom->students()->wherePivot('student_number', 3)->detach();
        }
    }

    public function test_queued_work_does_nothing_after_the_switch_goes_off(): void
    {
        $submission = $this->submission();
        config(['services.firebase.firestore_records' => false]);
        Http::fake();
        $store = new RecordingLiveStore;

        (new MirrorRecordJob(LiveRecords::SUBMISSIONS, $submission->id, $this->classroom->id))->handle($store);
        (new MirrorClassroomRecordsJob($this->classroom->id))->handle();

        $this->assertSame([], $store->ops);
        Http::assertNothingSent();
    }

    public function test_grading_one_paper_queues_one_job_for_the_hand_in(): void
    {
        Queue::fake();
        $submission = $this->submission();
        $response = $this->answer($submission, $this->question(), ['ai_score' => 1]);
        $response->forceFill(['final_score' => 0.5])->save();
        $submission->forceFill(['total_score' => 0.5, 'status' => Submission::STATUS_REVIEWED])->save();

        $this->assertCount(1, Queue::pushed(MirrorRecordJob::class, fn (MirrorRecordJob $job) => $job->collection === LiveRecords::SUBMISSIONS
            && $job->recordId === $submission->id && $job->classroomId === $this->classroom->id));
    }

    public function test_a_new_score_while_nothing_is_waiting_is_queued_again(): void
    {
        $submission = $this->submission();
        $response = $this->answer($submission, $this->question(), ['ai_score' => 1]);

        foreach ([
            fn () => $response->forceFill(['final_score' => 0.5])->save(),
            fn () => $response->forceFill(['extraction' => ['answer_text' => 'แก้แล้ว']])->save(),
            fn () => $submission->forceFill(['total_score' => 0.5])->save(),
            fn () => $submission->forceFill(['total_override' => 0.75])->save(),
            fn () => $submission->forceFill(['status' => Submission::STATUS_PUBLISHED])->save(),
        ] as $change) {
            $this->afterChange($change);
            Queue::assertPushed(MirrorRecordJob::class, 1);
        }

        // What the copy does not hold writes nothing. A row read back, because
        // wasRecentlyCreated stays true on the instance that created it.
        $this->afterChange(fn () => Response::query()->findOrFail($response->id)->forceFill(['review_priority' => 0.4])->save());
        Queue::assertNotPushed(MirrorRecordJob::class);
    }

    public function test_the_document_holds_the_total_and_each_answer_without_a_name_or_image(): void
    {
        $submission = $this->submission(attributes: ['total_score' => 3.5, 'status' => Submission::STATUS_PUBLISHED]);
        $mcq = $this->question();
        $short = $this->question('short');
        $this->answer($submission, $short, [
            'extraction' => ['answer_text' => '20'], 'ai_score' => 2, 'final_score' => 1.5,
            'crop_path' => 'crops/secret.webp',
        ]);
        $this->answer($submission, $mcq, ['mcq_fill' => ['A' => 0.1, 'B' => 0.9, 'C' => 0.3], 'ai_score' => 1]);

        $fields = $this->document(LiveRecords::SUBMISSIONS, $submission->id);

        $this->assertSame($this->assignment->id, $fields['assignment_id']);
        $this->assertSame('เศษส่วน', $fields['assignment_title']);
        $this->assertSame($this->student->id, $fields['student_id']);
        $this->assertSame(3.5, $fields['total_score']);
        $this->assertTrue($fields['published']);
        $this->assertSame([
            ['question_id' => $mcq->id, 'position' => 1, 'type' => 'mcq', 'answer' => 'B', 'score' => 1.0, 'max_points' => 1.0],
            ['question_id' => $short->id, 'position' => 2, 'type' => 'short', 'answer' => '20', 'score' => 1.5, 'max_points' => 2.0],
        ], $fields['answers']);
        $this->assertTrue($fields['expires_at']->eq($fields['updated_at']->addDays(90)));

        $encoded = (string) json_encode($fields, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('crops/', $encoded);
        $this->assertStringNotContainsString('เด็กชายเอ', $encoded);
    }

    public function test_the_classroom_total_from_google_wins_over_the_computed_one(): void
    {
        $submission = $this->submission(attributes: ['total_score' => 3.5, 'total_override' => 4.0]);

        $this->assertSame(4.0, $this->document(LiveRecords::SUBMISSIONS, $submission->id)['total_score']);
    }

    public function test_every_kind_of_answer_becomes_text(): void
    {
        $submission = $this->submission();
        $long = str_repeat('ก', LiveRecords::MAX_ANSWER_CHARS + 50);
        $this->answer($submission, $this->question('showWork'), ['extraction' => ['steps' => [['line' => 1, 'text' => '2 + 2']], 'final_answer_text' => '4']]);
        $this->answer($submission, $this->question('open'), ['extraction' => ['transcription' => $long]]);
        $this->answer($submission, $this->question(), ['extraction' => ['selected_options' => ['C']]]);
        $this->answer($submission, $this->question(), ['exam_answer' => ['selected' => [1, 2], 'value' => null]]);
        $this->answer($submission, $this->question(), ['exam_answer' => ['selected' => [1, 2], 'resolved' => ['selected' => [2]]]]);

        $answers = array_column($this->document(LiveRecords::SUBMISSIONS, $submission->id)['answers'], 'answer');

        $this->assertSame("2 + 2\n4", $answers[0]);
        $this->assertSame(LiveRecords::MAX_ANSWER_CHARS, mb_strlen($answers[1]), 'cut to fit a Firestore document');
        $this->assertSame(['C', '1,2', '2'], array_slice($answers, 2));
    }

    public function test_a_deleted_hand_in_is_queued_and_its_copy_removed(): void
    {
        $submission = $this->submission();

        $this->afterChange(fn () => $submission->delete());

        Queue::assertPushed(MirrorRecordJob::class, fn (MirrorRecordJob $job) => $job->recordId === $submission->id);
        $this->assertRemoved(LiveRecords::SUBMISSIONS, $submission->id);
    }

    public function test_the_copy_of_a_deleted_account_is_removed_not_rewritten(): void
    {
        $submission = $this->submission();
        $entry = GradebookEntry::create(['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id, 'assignment_id' => $this->assignment->id, 'score' => 3, 'updated_by' => $this->teacher->id]);
        $this->student->forceFill(['deleted_at' => now()])->save();

        $this->assertRemoved(LiveRecords::SUBMISSIONS, $submission->id);
        $this->assertRemoved(LiveRecords::GRADEBOOK, $entry->id);
    }

    public function test_a_typed_gradebook_score_follows_its_row(): void
    {
        $entry = null;
        $this->afterChange(function () use (&$entry) {
            $entry = GradebookEntry::create(['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id, 'assignment_id' => $this->assignment->id, 'score' => 7.5, 'updated_by' => $this->teacher->id]);
        });
        Queue::assertPushed(MirrorRecordJob::class, fn (MirrorRecordJob $job) => $job->collection === LiveRecords::GRADEBOOK && $job->recordId === $entry->id);

        $fields = $this->document(LiveRecords::GRADEBOOK, $entry->id);
        $this->assertSame(7.5, $fields['score']);
        $this->assertSame($this->student->id, $fields['student_id']);
        $this->assertSame($this->assignment->id, $fields['assignment_id']);
        $this->assertFalse($fields['excused']);

        $this->afterChange(fn () => $entry->delete());
        Queue::assertPushed(MirrorRecordJob::class, fn (MirrorRecordJob $job) => $job->recordId === $entry->id);
        $this->assertRemoved(LiveRecords::GRADEBOOK, $entry->id);
    }

    public function test_the_roster_follows_a_join_a_new_number_a_rename_and_a_removal(): void
    {
        $this->assertRosterQueued(fn () => $this->enrollStudent($this->classroom, 3));
        $this->assertRosterQueued(fn () => $this->classroom->students()->updateExistingPivot($this->student->id, ['student_number' => 9]));
        $this->assertRosterQueued(fn () => $this->student->forceFill(['name' => 'ชื่อใหม่'])->save());
        $this->assertRosterQueued(fn () => $this->student->forceFill(['student_code' => '999'])->save());
        $this->assertRosterQueued(fn () => $this->classroom->students()->detach($this->classmate->id));

        // A teacher's rename, or a student's other save, copies nothing.
        $this->afterChange(function () {
            $this->teacher->forceFill(['name' => 'ครูคนใหม่'])->save();
            $this->student->forceFill(['status' => User::STATUS_DISABLED])->save();
        });
        Queue::assertNotPushed(MirrorClassroomRecordsJob::class);
    }

    public function test_a_rename_reaches_every_classroom_of_the_student(): void
    {
        $other = $this->makeClassroom($this->teacher);
        $other->students()->attach($this->student->id, ['student_number' => 5]);

        $this->assertRosterQueued(fn () => $this->student->forceFill(['name' => 'ชื่อใหม่'])->save());
        Queue::assertPushed(MirrorClassroomRecordsJob::class, fn (MirrorClassroomRecordsJob $job) => $job->classroomId === $other->id);
    }

    public function test_merging_accounts_copies_the_kept_students_classrooms_again(): void
    {
        $this->assertClassroomQueued(fn () => app(StudentMerger::class)->merge($this->student, $this->classmate, $this->teacher));
    }

    public function test_deleting_and_restoring_an_account_syncs_its_classrooms(): void
    {
        $admin = $this->makeAdmin();
        $this->assertClassroomQueued(fn () => app(AccountDeletion::class)->delete($admin, $this->student));
        $this->assertClassroomQueued(fn () => app(AccountDeletion::class)->restore($admin, $this->student->refresh()));
        $this->assertClassroomQueued(fn () => app(AccountDeletion::class)->delete($admin, $this->teacher));
        $this->assertClassroomQueued(fn () => app(AccountDeletion::class)->restore($admin, $this->teacher->refresh()));
    }

    public function test_a_new_title_and_a_deleted_classroom_sync_the_whole_classroom(): void
    {
        $this->assertClassroomQueued(fn () => $this->assignment->forceFill(['title' => 'ชื่อใหม่'])->save());
        $room = $this->makeClassroom($this->teacher);
        $this->assertClassroomQueued(fn () => $room->delete(), $room->id);
    }

    public function test_a_full_sync_writes_the_classroom_and_removes_what_is_stale(): void
    {
        $submission = $this->submission();
        $entry = GradebookEntry::create(['classroom_id' => $this->classroom->id, 'student_id' => $this->student->id, 'assignment_id' => $this->assignment->id, 'score' => 3, 'updated_by' => $this->teacher->id]);
        $this->fakeFirestore([
            LiveRecords::STUDENTS => [(string) $this->student->id, '999998'],
            LiveRecords::SUBMISSIONS => [(string) $submission->id, '999997'],
            LiveRecords::GRADEBOOK => ['999996'],
        ]);

        (new MirrorClassroomRecordsJob($this->classroom->id))->handle();

        $room = $this->classroom->id;
        $commits = $this->commits();
        $this->assertEqualsCanonicalizing([
            "classrooms/{$room}/students/{$this->student->id}",
            "classrooms/{$room}/students/{$this->classmate->id}",
            "classrooms/{$room}/submissions/{$submission->id}",
            "classrooms/{$room}/gradebook/{$entry->id}",
        ], $commits['written']);
        $this->assertEqualsCanonicalizing([
            "classrooms/{$room}/students/999998",
            "classrooms/{$room}/submissions/999997",
            "classrooms/{$room}/gradebook/999996",
        ], $commits['deleted']);

        $student = $commits['fields']["classrooms/{$room}/students/{$this->student->id}"];
        $this->assertSame('เด็กชายเอ', $student['name']['stringValue']);
        $this->assertSame('1', $student['student_number']['integerValue']);
        $this->assertSame('12345', $student['student_code']['stringValue']);
        $this->assertSame(['classroom_id', 'expires_at', 'name', 'student_code', 'student_id', 'student_number', 'updated_at'], collect($student)->keys()->sort()->values()->all());
    }

    public function test_a_roster_sync_leaves_the_scores_alone(): void
    {
        $this->submission();
        $this->fakeFirestore();

        (new MirrorClassroomRecordsJob($this->classroom->id, rosterOnly: true))->handle();

        $this->assertCount(2, $this->commits()['written']);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/submissions'));
    }

    public function test_a_full_sync_leaves_out_a_deleted_student_and_removes_the_copies(): void
    {
        $submission = $this->submission();
        $kept = $this->submission($this->classmate);
        $this->student->forceFill(['deleted_at' => now()])->save();
        $this->fakeFirestore([
            LiveRecords::STUDENTS => [(string) $this->student->id, (string) $this->classmate->id],
            LiveRecords::SUBMISSIONS => [(string) $submission->id, (string) $kept->id],
        ]);

        (new MirrorClassroomRecordsJob($this->classroom->id))->handle();

        $room = $this->classroom->id;
        $commits = $this->commits();
        $this->assertEqualsCanonicalizing([
            "classrooms/{$room}/students/{$this->classmate->id}",
            "classrooms/{$room}/submissions/{$kept->id}",
        ], $commits['written']);
        $this->assertEqualsCanonicalizing([
            "classrooms/{$room}/students/{$this->student->id}",
            "classrooms/{$room}/submissions/{$submission->id}",
        ], $commits['deleted']);
    }

    public function test_a_deleted_classroom_loses_its_whole_copy(): void
    {
        $this->classroom->forceFill(['deleted_at' => now()])->save();
        $this->fakeFirestore([LiveRecords::STUDENTS => ['1', '2']]);

        (new MirrorClassroomRecordsJob($this->classroom->id))->handle();

        $room = $this->classroom->id;
        $commits = $this->commits();
        $this->assertSame([], $commits['written']);
        $this->assertSame(["classrooms/{$room}/students/1", "classrooms/{$room}/students/2"], $commits['deleted']);
    }

    public function test_a_classroom_gone_from_the_database_loses_its_copy_too(): void
    {
        $this->classroom = $this->makeClassroom($this->teacher);
        $room = $this->classroom->id;
        $this->classroom->delete();
        $this->fakeFirestore([LiveRecords::STUDENTS => ['1']]);

        (new MirrorClassroomRecordsJob($room))->handle();

        $this->assertSame(["classrooms/{$room}/students/1"], $this->commits()['deleted']);
    }

    public function test_a_failed_commit_fails_the_job_so_it_retries(): void
    {
        $this->fakeFirestore(commitStatus: 503);

        $this->expectException(FirestoreUnavailable::class);
        (new MirrorClassroomRecordsJob($this->classroom->id))->handle();
    }

    public function test_the_command_queues_every_live_classroom_and_only_when_on(): void
    {
        $gone = $this->makeClassroom($this->teacher);
        $gone->forceFill(['deleted_at' => now()])->save();
        Cache::flush();
        Queue::fake();

        $this->artisan('eduvision:mirror-records')->assertSuccessful()->expectsOutputToContain('queued 1 classroom(s)');

        Queue::assertPushed(MirrorClassroomRecordsJob::class, 1);
        Queue::assertPushed(MirrorClassroomRecordsJob::class, fn (MirrorClassroomRecordsJob $job) => $job->classroomId === $this->classroom->id && ! $job->rosterOnly);

        config(['services.firebase.firestore_records' => false]);
        Cache::flush();
        Queue::fake();
        $this->artisan('eduvision:mirror-records')->assertFailed();
        Queue::assertNothingPushed();
    }

    public function test_the_privacy_page_tells_what_this_server_really_copies(): void
    {
        $this->get('/privacy')->assertOk()
            ->assertSee('คะแนน และคำตอบที่อ่านได้จากงาน')
            ->assertDontSee('ระบบไม่ส่งชื่อ อีเมล คะแนน หรือภาพงาน');

        config(['services.firebase.firestore_records' => false]);
        $this->get('/privacy')->assertOk()
            ->assertSee('ระบบไม่ส่งชื่อ อีเมล คะแนน หรือภาพงาน')
            ->assertDontSee('คะแนน และคำตอบที่อ่านได้จากงาน');
    }

    /** The promise that keeps unpublished scores from students lives in this file. */
    public function test_no_client_rule_opens_the_student_records(): void
    {
        $rules = (string) file_get_contents(base_path('../firebase/firestore.rules'));

        $this->assertMatchesRegularExpression('~match /\{document=\*\*\} \{\s*allow read, write: if false;~', $rules);
        $this->assertDoesNotMatchRegularExpression('~match /classrooms/[^\n]*(students|submissions|gradebook|\{document=\*\*\})~', $rules);
    }
}
