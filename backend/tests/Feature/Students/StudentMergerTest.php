<?php

namespace Tests\Feature\Students;

use App\Domain\Mastery\MasteryCalculator;
use App\Domain\Students\StudentMerger;
use App\Exceptions\ApiException;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\GradebookEntry;
use App\Models\GradebookItem;
use App\Models\GradebookPublication;
use App\Models\GradebookPublishedGrade;
use App\Models\GradebookSpecialGrade;
use App\Models\Mastery;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Skill;
use App\Models\SkillObservation;
use App\Models\StudentAnalysis;
use App\Models\StudentMerge;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DESIGN §24.5 "รวมบัญชีนักเรียน": every row of the collision table, the
 * conflicts that refuse a merge with nothing written, mastery recomputed
 * from scratch, D locked out everywhere, the audit row and who may merge.
 */
class StudentMergerTest extends TestCase
{
    use RefreshDatabase;

    private User $teacher;

    private Classroom $room1;

    private Classroom $room2;

    private Course $course;

    /** @var array{student: User, pin: string, qr_token: string} */
    private array $keep;

    /** @var array{student: User, pin: string, qr_token: string} */
    private array $merge;

    private Skill $skill;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->teacher = $this->makeTeacher();
        $this->room1 = $this->makeClassroom($this->teacher, ['name' => 'ป.5/1']);
        $this->room2 = $this->makeClassroom($this->teacher, ['name' => 'ป.5/2']);
        $this->course = $this->makeCourse($this->teacher, [$this->room1, $this->room2]);
        $this->keep = $this->enrollStudent($this->room1, 1, 'สมชาย ใจดี');
        $this->merge = $this->enrollStudent($this->room2, 5, 'ด.ช.สมชาย ใจดี');
        $this->skill = Skill::factory()->create(['subject_id' => Subject::query()->firstOrFail()->id]);
    }

    public function test_a_merge_moves_everything_and_locks_the_merged_account_out(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $d->forceFill(['student_code' => '6601'])->save();
        $assignment = $this->assignment($this->room2);
        $submission = $this->realSubmission($assignment, $d, Submission::STATUS_PUBLISHED);
        $response = Response::query()->where('submission_id', $submission->id)->firstOrFail();
        $appeal = Appeal::create(['response_id' => $response->id, 'student_id' => $d->id, 'reason' => 'ขอตรวจใหม่']);
        $this->observe($k, 0.2, '2026-09-01 08:00:00');
        $this->observe($d, 1.0, '2026-09-02 08:00:00', $response->id);
        $this->observe($k, 0.6, '2026-09-03 08:00:00');
        $item = $this->item($this->room2);
        $entry = GradebookEntry::create(['classroom_id' => $this->room2->id, 'student_id' => $d->id, 'gradebook_item_id' => $item->id, 'score' => 7, 'updated_by' => $this->teacher->id]);
        GradebookSpecialGrade::create(['course_id' => $this->course->id, 'classroom_id' => $this->room2->id, 'student_id' => $d->id, 'special' => 'r', 'set_by' => $this->teacher->id]);
        $analysis = $this->analysis($d, $this->room2);
        $d->createToken('phone', ['student']);
        DB::table('device_tokens')->insert(['user_id' => $d->id, 'fcm_token' => 'fcm-d', 'created_at' => now(), 'updated_at' => now()]);
        $dToken = $d->createToken('phone2', ['student'])->plainTextToken;

        $this->asUser($this->teacher)->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $d->id])
            ->assertOk()
            ->assertJsonPath('data.kept_student.id', $k->id)
            ->assertJsonPath('data.kept_student.student_code', '6601')
            ->assertJsonCount(2, 'data.kept_student.classrooms');

        $d->refresh();
        $this->assertSame('disabled', $d->status);
        $this->assertSame($k->id, $d->merged_into_id);
        $this->assertNull($d->student_code);
        $this->assertSame('สมชาย ใจดี', $k->refresh()->name);
        $this->assertDatabaseHas('classroom_students', ['classroom_id' => $this->room2->id, 'student_id' => $k->id, 'student_number' => 5]);
        $this->assertDatabaseMissing('classroom_students', ['student_id' => $d->id]);
        $this->assertSame($k->id, $submission->refresh()->student_id);
        $this->assertSame($k->id, $appeal->refresh()->student_id);
        $this->assertSame($k->id, $entry->refresh()->student_id);
        $this->assertDatabaseHas('gradebook_special_grades', ['student_id' => $k->id, 'classroom_id' => $this->room2->id]);
        $this->assertSame($k->id, $analysis->refresh()->student_id);
        $this->assertSame(0, SkillObservation::query()->where('student_id', $d->id)->count());
        $this->assertSame(3, SkillObservation::query()->where('student_id', $k->id)->count());
        $this->assertSame(0, Mastery::query()->where('student_id', $d->id)->count());

        // Mastery of K equals a computation from scratch over all three observations in time order.
        $expected = MasteryCalculator::ewma([
            ['score_ratio' => 0.2, 'source' => 'homework'], ['score_ratio' => 1.0, 'source' => 'homework'], ['score_ratio' => 0.6, 'source' => 'homework'],
        ]);
        $mastery = MasteryCalculator::find($k->id, $this->skill->id);
        $this->assertEqualsWithDelta($expected['value'], $mastery->value, 0.0001);
        $this->assertSame(3, $mastery->n_obs);

        // D: no credential, no session, no push token; PIN, QR and the old token fail.
        $this->assertDatabaseMissing('student_credentials', ['student_id' => $d->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $d->id]);
        $this->assertDatabaseMissing('device_tokens', ['user_id' => $d->id]);
        $this->asGuest()->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $this->room2->class_code, 'student_number' => 5, 'pin' => $this->merge['pin']]))->assertStatus(422)->assertJsonPath('code', 'invalid_credentials');
        $this->postJson('/api/v1/auth/student/qr', ['qr_token' => $this->merge['qr_token']])->assertStatus(422)->assertJsonPath('code', 'qr_invalid');
        $this->forgetGuards();
        $this->withToken($dToken)->getJson('/api/v1/me')->assertStatus(401);
        // K logs in through D's old classroom with K's own PIN and D's old number there.
        $this->asGuest()->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $this->room2->class_code, 'student_number' => 5, 'pin' => $this->keep['pin']]))
            ->assertOk()->assertJsonPath('user.id', $k->id);

        $record = StudentMerge::query()->sole();
        $this->assertSame([$k->id, $d->id, $this->teacher->id], [$record->kept_student_id, $record->merged_student_id, $record->merged_by]);
        $this->assertSame([$this->room2->id], $record->summary['classroom_students']['moved']);
        $this->assertSame([$submission->id], $record->summary['submissions']['moved']);
        $this->assertSame([$this->skill->id], $record->summary['mastery']['recomputed_skill_ids']);
        $this->assertSame('6601', $record->summary['users']['student_code_moved']);
    }

    public function test_the_same_classroom_keeps_k_and_takes_ds_google_account(): void
    {
        $k = $this->keep['student'];
        $d = $this->enrollStudent($this->room1, 2, 'สมชาย ซ้ำ')['student'];
        DB::table('classroom_students')->where('student_id', $d->id)->update(['google_user_id' => 'g-9', 'google_email' => 'som@school.ac.th']);

        $this->mergeOk($k, $d);

        $rows = DB::table('classroom_students')->where('classroom_id', $this->room1->id)->get();
        $this->assertCount(1, $rows);
        $this->assertSame([$k->id, 1, 'g-9', 'som@school.ac.th'], [(int) $rows[0]->student_id, (int) $rows[0]->student_number, $rows[0]->google_user_id, $rows[0]->google_email]);
        $summary = StudentMerge::query()->sole()->summary['classroom_students'];
        $this->assertSame([$this->room1->id], $summary['kept']);
        $this->assertSame('g-9', $summary['dropped'][0]['google_user_id']);
        $this->assertArrayNotHasKey('google_email', $summary['dropped'][0], 'no e-mail in the summary (§24.14)');
    }

    public function test_a_pin_pending_row_of_d_is_not_pending_for_k_who_has_a_pin(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        DB::table('classroom_students')->where('student_id', $d->id)->update(['pin_pending_at' => now()]);

        $this->mergeOk($k, $d);

        $this->assertNull(DB::table('classroom_students')->where('classroom_id', $this->room2->id)->where('student_id', $k->id)->value('pin_pending_at'));
        $this->asGuest()->postJson('/api/v1/auth/student/login', $this->cred(['class_code' => $this->room2->class_code, 'student_number' => 5, 'pin' => $this->keep['pin']]))
            ->assertOk()->assertJsonPath('user.id', $k->id);
    }

    public function test_an_empty_submission_gives_way_to_real_work_on_either_side(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $a1 = $this->assignment($this->room1);
        $a2 = $this->assignment($this->room1);
        $emptyD = Submission::create(['assignment_id' => $a1->id, 'student_id' => $d->id, 'status' => Submission::STATUS_AWAITING_SCAN]);
        $realK = $this->realSubmission($a1, $k, Submission::STATUS_NEEDS_REVIEW);
        $emptyK = Submission::create(['assignment_id' => $a2->id, 'student_id' => $k->id, 'status' => Submission::STATUS_AWAITING_SCAN]);
        $realD = $this->realSubmission($a2, $d, Submission::STATUS_NEEDS_REVIEW);

        $this->mergeOk($k, $d);

        $this->assertNull(Submission::find($emptyD->id));
        $this->assertNull(Submission::find($emptyK->id));
        $this->assertSame($k->id, $realK->refresh()->student_id);
        $this->assertSame($k->id, $realD->refresh()->student_id);
        $this->assertCount(2, StudentMerge::query()->sole()->summary['submissions']['dropped']);
    }

    public function test_real_work_of_both_on_one_assignment_refuses_and_writes_nothing(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $a = $this->assignment($this->room1, 'ใบงานเศษส่วน');
        $this->realSubmission($a, $k, Submission::STATUS_NEEDS_REVIEW);
        $this->realSubmission($a, $d, Submission::STATUS_NEEDS_REVIEW);

        $this->asUser($this->teacher)->getJson("/api/v1/students/merge-preview?keep_id={$k->id}&merge_id={$d->id}")
            ->assertOk()
            ->assertJsonPath('data.can_merge', false)
            ->assertJsonPath('data.conflicts.0.type', 'submissions')
            ->assertJsonPath('data.conflicts.0.message', 'ทั้งสองบัญชีมีงาน ใบงานเศษส่วน ห้อง ป.5/1');
        $this->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $d->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'merge_conflict')
            ->assertJsonPath('errors.submissions.0', 'ทั้งสองบัญชีมีงาน ใบงานเศษส่วน ห้อง ป.5/1');

        $this->assertNothingMerged($d);
    }

    public function test_gradebook_values_equal_are_dropped_and_different_ones_refuse(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $item = $this->item($this->room1);
        GradebookEntry::create(['classroom_id' => $this->room1->id, 'student_id' => $k->id, 'gradebook_item_id' => $item->id, 'score' => 8, 'updated_by' => $this->teacher->id]);
        $dEntry = GradebookEntry::create(['classroom_id' => $this->room1->id, 'student_id' => $d->id, 'gradebook_item_id' => $item->id, 'score' => 9, 'updated_by' => $this->teacher->id]);
        GradebookSpecialGrade::create(['course_id' => $this->course->id, 'classroom_id' => $this->room1->id, 'student_id' => $k->id, 'special' => 'r', 'set_by' => $this->teacher->id]);
        GradebookSpecialGrade::create(['course_id' => $this->course->id, 'classroom_id' => $this->room1->id, 'student_id' => $d->id, 'special' => 'ms', 'set_by' => $this->teacher->id]);

        $this->asUser($this->teacher)->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $d->id])
            ->assertStatus(409)
            ->assertJsonStructure(['errors' => ['gradebook_entries', 'gradebook_special_grades']]);
        $this->assertNothingMerged($d);

        $dEntry->forceFill(['score' => 8])->save();
        DB::table('gradebook_special_grades')->where('student_id', $d->id)->update(['special' => 'r']);
        $this->mergeOk($k, $d);

        $this->assertSame(1, GradebookEntry::query()->count());
        $this->assertSame($k->id, GradebookEntry::query()->sole()->student_id);
        $this->assertSame(1, GradebookSpecialGrade::query()->count());
        $this->assertSame([$dEntry->id], array_column(StudentMerge::query()->sole()->summary['gradebook_entries']['dropped'], 'id'));
    }

    public function test_the_published_grade_of_k_wins(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $publication = GradebookPublication::create(['course_id' => $this->course->id, 'classroom_id' => $this->room1->id, 'categories' => [], 'cutoffs' => [], 'published_by' => $this->teacher->id, 'published_at' => now()]);
        $other = GradebookPublication::create(['course_id' => $this->course->id, 'classroom_id' => $this->room2->id, 'categories' => [], 'cutoffs' => [], 'published_by' => $this->teacher->id, 'published_at' => now()]);
        GradebookPublishedGrade::create(['publication_id' => $publication->id, 'student_id' => $k->id, 'breakdown' => [], 'total' => 80, 'total_rounded' => 80, 'grade' => 4]);
        GradebookPublishedGrade::create(['publication_id' => $publication->id, 'student_id' => $d->id, 'breakdown' => [], 'total' => 50, 'total_rounded' => 50, 'grade' => 1]);
        GradebookPublishedGrade::create(['publication_id' => $other->id, 'student_id' => $d->id, 'breakdown' => [], 'total' => 70, 'total_rounded' => 70, 'grade' => 3]);

        $this->mergeOk($k, $d);

        $this->assertSame(['80.0000', '70.0000'], DB::table('gradebook_published_grades')->where('student_id', $k->id)->orderBy('publication_id')->pluck('total')->map(fn ($t) => number_format((float) $t, 4, '.', ''))->all());
        $this->assertSame(0, DB::table('gradebook_published_grades')->where('student_id', $d->id)->count());
        $dropped = StudentMerge::query()->sole()->summary['gradebook_published_grades']['dropped'];
        $this->assertSame($publication->id, $dropped[0]['publication_id']);
    }

    public function test_an_analysis_of_the_same_classroom_keeps_ks_and_recomputes_it(): void
    {
        $k = $this->keep['student'];
        $d = $this->enrollStudent($this->room1, 2)['student'];
        $kAnalysis = $this->analysis($k, $this->room1);
        $dAnalysis = $this->analysis($d, $this->room1);

        $this->mergeOk($k, $d);

        $this->assertNull(StudentAnalysis::find($dAnalysis->id));
        $this->assertNotSame(str_repeat('c', 64), $kAnalysis->refresh()->computed_input_hash);
    }

    public function test_two_different_student_codes_refuse(): void
    {
        $this->keep['student']->forceFill(['student_code' => '1'])->save();
        $this->merge['student']->forceFill(['student_code' => '2'])->save();

        $this->asUser($this->teacher)->postJson('/api/v1/students/merge', ['keep_id' => $this->keep['student']->id, 'merge_id' => $this->merge['student']->id])
            ->assertStatus(409)->assertJsonStructure(['errors' => ['student_code']]);
        $this->assertNothingMerged($this->merge['student']);
    }

    public function test_invalid_pairs(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $this->asUser($this->teacher);
        $this->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $k->id])->assertStatus(422)->assertJsonPath('code', 'merge_invalid');
        $this->mergeOk($k, $d);
        $this->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $d->id])->assertStatus(422)->assertJsonPath('code', 'merge_invalid');
        $this->postJson('/api/v1/students/merge', ['keep_id' => $d->id, 'merge_id' => $k->id])->assertStatus(422)->assertJsonPath('code', 'merge_invalid');
        // Another school's student is not visible at all.
        $stranger = $this->enrollStudent($this->makeClassroom($this->makeTeacher()), 1)['student'];
        $this->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $stranger->id])->assertNotFound();
        // A teacher (and a student) is not a student account.
        $this->assertSame('merge_invalid', $this->invalidCode(fn () => StudentMerger::assertValid($k, $this->teacher)));
        $this->assertSame('merge_invalid', $this->invalidCode(fn () => StudentMerger::assertValid($k, $stranger)));
    }

    public function test_only_the_homeroom_teacher_of_both_accounts_merges(): void
    {
        $colleague = $this->makeTeacher($this->teacher->school);
        $theirs = $this->enrollStudent($this->makeClassroom($colleague), 1, 'ห้องครูอื่น')['student'];
        $k = $this->keep['student'];

        $this->asUser($this->teacher)->postJson('/api/v1/students/merge', ['keep_id' => $k->id, 'merge_id' => $theirs->id])
            ->assertStatus(403)->assertJsonPath('code', 'not_homeroom_teacher');
        $this->getJson("/api/v1/students/merge-preview?keep_id={$k->id}&merge_id={$theirs->id}")->assertStatus(403);
        $this->assertNothingMerged($theirs);

        // A closed classroom still counts (§24.5).
        $this->room2->forceFill(['closed_at' => now()])->save();
        $this->mergeOk($k, $this->merge['student']);
    }

    public function test_the_preview_compares_both_accounts(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $this->realSubmission($this->assignment($this->room2), $d, Submission::STATUS_PUBLISHED);
        DB::table('classroom_students')->where('student_id', $d->id)->update(['google_user_id' => 'g', 'google_email' => 'D@School.ac.th']);

        $data = $this->asUser($this->teacher)->getJson("/api/v1/students/merge-preview?keep_id={$k->id}&merge_id={$d->id}")->assertOk()->json('data');

        $this->assertTrue($data['can_merge']);
        $this->assertSame([], $data['conflicts']);
        $this->assertSame(['total' => 1, 'published' => 1], $data['merge']['submissions']);
        $this->assertSame([['id' => $this->room2->id, 'name' => 'ป.5/2', 'academic_year' => $this->room2->academic_year, 'student_number' => 5, 'closed' => false]], $data['merge']['classrooms']);
        $this->assertSame(['d@school.ac.th'], $data['merge']['google_emails']);
        $this->assertSame(0, $data['keep']['submissions']['total']);
        $this->assertNothingMerged($d);
    }

    public function test_an_exception_half_way_rolls_everything_back(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $this->realSubmission($this->assignment($this->room2), $d, Submission::STATUS_PUBLISHED);
        // The very last write of a merge fails. (A hook on the connection rather than a
        // trigger: CREATE TRIGGER commits the test's transaction on MariaDB.)
        DB::connection()->beforeExecuting(function (string $query, array $bindings, $connection) {
            if (str_starts_with(strtolower($query), 'update') && str_contains($query, 'merged_into_id')) {
                throw new QueryException($connection->getName(), $query, $bindings, new \RuntimeException('boom'));
            }
        });

        try {
            app(StudentMerger::class)->merge($k, $d, $this->teacher);
            $this->fail('the merge should have failed');
        } catch (QueryException) {
            // expected
        }

        $this->assertNothingMerged($d);
        $this->assertDatabaseHas('student_credentials', ['student_id' => $d->id]);
        $this->assertSame(1, Submission::query()->where('student_id', $d->id)->count());
    }

    private function mergeOk(User $keep, User $merge): void
    {
        $this->asUser($this->teacher)->postJson('/api/v1/students/merge', ['keep_id' => $keep->id, 'merge_id' => $merge->id])->assertOk();
    }

    private function assertNothingMerged(User $merge): void
    {
        $merge->refresh();
        $this->assertSame('active', $merge->status);
        $this->assertNull($merge->merged_into_id);
        $this->assertSame(0, StudentMerge::query()->count());
        $this->assertTrue(DB::table('classroom_students')->where('student_id', $merge->id)->exists());
    }

    private function invalidCode(callable $call): ?string
    {
        try {
            $call();
        } catch (ApiException $e) {
            return $e->errorCode;
        }

        return null;
    }

    private function assignment(Classroom $classroom, string $title = 'การบ้าน'): Assignment
    {
        return Assignment::factory()->for_classroom($classroom)->create(['title' => $title, 'course_id' => $this->course->id, 'status' => Assignment::STATUS_READY]);
    }

    /** A submission with a scan and an answer: real work. */
    private function realSubmission(Assignment $assignment, User $student, string $status): Submission
    {
        $submission = Submission::create(['assignment_id' => $assignment->id, 'student_id' => $student->id, 'status' => $status]);
        $question = Question::factory()->short()->create(['assignment_id' => $assignment->id, 'position' => Question::query()->where('assignment_id', $assignment->id)->count() + 1]);
        $scan = Scan::create([
            'client_scan_id' => (string) Str::uuid(), 'submission_id' => $submission->id, 'page_no' => 1, 'layout_version' => 1,
            'uploaded_by' => $this->teacher->id, 'scanned_at' => now(), 'blur_score' => 150.0, 'state' => Scan::STATE_ACTIVE,
        ]);
        Response::create(['submission_id' => $submission->id, 'question_id' => $question->id, 'scan_id' => $scan->id]);

        return $submission;
    }

    private function observe(User $student, float $ratio, string $at, ?int $responseId = null): void
    {
        SkillObservation::create([
            'student_id' => $student->id, 'skill_id' => $this->skill->id, 'source' => SkillObservation::SOURCE_HOMEWORK,
            'response_id' => $responseId, 'score_ratio' => $ratio, 'observed_at' => $at,
        ]);
        app(MasteryCalculator::class)->recompute($student->id, $this->skill->id);
    }

    private function item(Classroom $classroom): GradebookItem
    {
        $category = GradebookCategory::query()->firstOrCreate(['course_id' => $this->course->id, 'position' => 1], ['name' => 'คะแนนเก็บ', 'weight' => 100]);

        return GradebookItem::create([
            'course_id' => $this->course->id, 'classroom_id' => $classroom->id, 'category_id' => $category->id,
            'name' => 'จิตพิสัย', 'max_points' => 10, 'position' => 1, 'created_by' => $this->teacher->id,
        ]);
    }

    private function analysis(User $student, Classroom $classroom): StudentAnalysis
    {
        return StudentAnalysis::create([
            'student_id' => $student->id, 'classroom_id' => $classroom->id,
            'computed_input_hash' => str_repeat('c', 64), 'strengths' => [], 'areas' => [],
            'status' => StudentAnalysis::STATUS_COMPUTED,
        ]);
    }

    public function test_google_sign_in_links_move_to_the_kept_account_unless_both_have_one(): void
    {
        $k = $this->keep['student'];
        $d = $this->merge['student'];
        $link = fn (User $user, string $sub) => UserGoogleIdentity::create(['user_id' => $user->id, 'google_sub' => $sub, 'email' => "{$sub}@school.ac.th", 'linked_via' => 'pin_confirm', 'linked_by' => $user->id, 'linked_at' => now()]);
        $link($k, 'k-sub');
        $dLink = $link($d, 'd-sub');

        $preview = app(StudentMerger::class)->preview($k, $d);
        $this->assertFalse($preview['can_merge']);
        $this->assertSame(['google'], array_column($preview['conflicts'], 'type'));
        $this->assertSame(['d-sub@school.ac.th'], $preview['merge']['google_emails']);
        try {
            app(StudentMerger::class)->merge($k, $d, $this->teacher);
            $this->fail('merged two Google links');
        } catch (ApiException $e) {
            $this->assertSame('merge_conflict', $e->errorCode);
            $this->assertArrayHasKey('google', $e->errors);
        }

        UserGoogleIdentity::query()->where('user_id', $k->id)->delete();
        $record = app(StudentMerger::class)->merge($k, $d, $this->teacher);

        $moved = $dLink->refresh();
        $this->assertSame($k->id, $moved->user_id);
        $this->assertSame($k->id, $moved->linked_by);
        $this->assertSame([$dLink->id], $record->summary['user_google_identities']['moved']);
    }
}
