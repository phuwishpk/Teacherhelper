<?php

namespace App\Domain\Google;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Classrooms\ClosedClassrooms;
use App\Domain\Classrooms\CourseRequests;
use App\Domain\Classrooms\GradeLevelGuesser;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Classrooms\ThaiNameSorter;
use App\Domain\Students\SchoolStudents;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\ClassroomGoogleIgnoredUser;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\Course;
use App\Models\User;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * "นำเข้าจาก Google Classroom" (DESIGN §19.2): a classroom, its students,
 * the course link and every account match made in one step.
 *
 * The preview proposes a name, a grade level, the Buddhist academic year and
 * student numbers in ThaiNameSorter order. The import reads the roster from
 * Google again: names and e-mails come from Google, never from the client,
 * which only chooses numbers and which accounts to leave out (those go to
 * classroom_google_ignored_users so a roster sync does not add them back).
 * An account that joined the course after the preview is appended after the
 * highest number; one that left is skipped. Everything happens in one
 * transaction: if any part fails, nothing is written.
 *
 * Since build 4 (DESIGN §24.10) the preview matches every account with the
 * school's existing students (SchoolStudentMatcher, `students[].match`) and
 * suggests an open classroom of the school that already holds most of the
 * roster (`suggested_classroom`). The import enrols a row with student_id
 * with that student's one account (no new PIN) and creates the others, and
 * linkExisting() binds the course to an existing classroom instead: at once
 * for its homeroom teacher (or a subject teacher whose course is bound to
 * it already), else as a course request (origin classroom_import).
 */
final class ClassroomImporter
{
    /** StudentEnroller is called for at most this many students at a time. */
    public const CHUNK = 100;

    /** classroom_students.student_number is a TINYINT UNSIGNED. */
    public const MAX_STUDENT_NUMBER = 255;

    /** An import of 255 students (bcrypt PINs included) ends well within this. */
    private const COURSE_LOCK_SECONDS = 60;

    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly StudentEnroller $enroller,
        private readonly SchoolStudentMatcher $matcher,
        private readonly SchoolStudents $schoolStudents,
        private readonly GoogleRosterSync $rosterSync,
        private readonly CourseRequests $courseRequests,
    ) {}

    /**
     * GET /google/courses/{course_id}/import-preview.
     *
     * students[].match is the existing student of the school the account is
     * (SchoolStudentMatcher, all four passes) with the classrooms they are
     * in; suggested_classroom is the open classroom of the school holding
     * the most matched accounts, when it holds at least
     * eduvision.classroom_suggest_threshold of the roster.
     *
     * @return array{course_id: string, name: string, section: string|null, suggested_name: string, grade_level_guess: int|null, academic_year: int, students: list<array<string, mixed>>, suggested_classroom: array<string, mixed>|null}
     */
    public function preview(User $teacher, string $courseId): array
    {
        self::assertNotLinked($courseId);
        [$course, $accounts] = $this->courseAndRoster($teacher, $courseId);

        $matches = $this->matcher->match((int) $teacher->school_id, $accounts);
        $students = User::query()->whereIn('id', array_column($matches, 'student_id'))->get(['id', 'name', 'student_code']);
        $payloads = collect($this->schoolStudents->payloads($students))->keyBy('id');

        $rows = [];
        foreach (self::sorted($accounts) as $i => $account) {
            $match = $matches[$account['google_user_id']] ?? null;
            $payload = $match === null ? null : $payloads->get($match['student_id']);
            $rows[] = [
                'google_user_id' => $account['google_user_id'],
                'name' => GoogleRoster::studentName($account),
                'email' => $account['email'],
                'proposed_number' => $i + 1,
                'match' => $payload === null ? null : [
                    'student_id' => $payload['id'],
                    'name' => $payload['name'],
                    'matched_by' => $match['matched_by'],
                    'classes' => $payload['classrooms'],
                ],
            ];
        }

        return [
            'course_id' => $course['course_id'],
            'name' => $course['name'],
            'section' => $course['section'],
            'suggested_name' => self::suggestedName($course),
            'grade_level_guess' => GradeLevelGuesser::guess($course['name'], $course['section']),
            'academic_year' => self::currentAcademicYear(),
            'students' => $rows,
            'suggested_classroom' => $this->suggestedClassroom($teacher, count($accounts), $rows),
        ];
    }

    /**
     * The open classroom of the school with the most matched accounts, if it
     * covers enough of the roster (ties: the newer academic year, then the
     * lower id).
     *
     * @param  list<array<string, mixed>>  $rows  preview rows with `match`
     * @return array{id: int, name: string, academic_year: int, homeroom_teacher: array{id: int, name: string}|null, coverage: float, matched: int, owned_by_me: bool}|null
     */
    private function suggestedClassroom(User $teacher, int $rosterSize, array $rows): ?array
    {
        if ($rosterSize === 0) {
            return null;
        }
        $counts = [];
        foreach ($rows as $row) {
            foreach ($row['match']['classes'] ?? [] as $class) {
                if (! $class['closed']) {
                    $counts[$class['id']] = ($counts[$class['id']] ?? 0) + 1;
                }
            }
        }
        if ($counts === []) {
            return null;
        }
        $classrooms = Classroom::query()
            ->with('teacher:id,name')
            ->where('school_id', $teacher->school_id)
            ->whereNull('closed_at')
            ->whereIn('id', array_keys($counts))
            ->get()
            ->sort(fn (Classroom $a, Classroom $b) => [$counts[$b->id], $b->academic_year, $a->id] <=> [$counts[$a->id], $a->academic_year, $b->id])
            ->values();
        $best = $classrooms->first();
        if ($best === null) {
            return null;
        }
        $coverage = $counts[$best->id] / $rosterSize;
        if ($coverage < (float) config('eduvision.classroom_suggest_threshold')) {
            return null;
        }

        return [
            'id' => $best->id,
            'name' => $best->name,
            'academic_year' => (int) $best->academic_year,
            'homeroom_teacher' => $best->teacher === null ? null : ['id' => $best->teacher->id, 'name' => $best->teacher->name],
            'coverage' => round($coverage, 4),
            'matched' => $counts[$best->id],
            'owned_by_me' => (int) $best->teacher_id === (int) $teacher->id,
        ];
    }

    /**
     * POST /classrooms/import-google.
     *
     * A row with student_id enrols that existing student of the school (their
     * PIN and QR card stay, pin = null); the others become new students. An
     * account that joined the course after the preview is matched with the
     * school's students (passes 1-3) and appended after the highest number.
     *
     * @param  array{course_id: string, name: string, grade_level: int, academic_year: int, students: list<array{google_user_id: string, student_number: int, student_id: int|null}>, removed: list<string>}  $input
     * @return array{classroom: Classroom, students: list<array{student: User, student_number: int, pin: string|null, existing: bool}>}
     *
     * @throws ApiException 409 course_already_linked, 422 student_not_in_school
     * @throws ValidationException an account both kept and removed, too many students
     */
    public function import(User $teacher, array $input): array
    {
        $courseId = $input['course_id'];
        self::assertNotLinked($courseId);
        $this->assertStudentsOfSchool($teacher, $input['students']);
        [$course, $accounts] = $this->courseAndRoster($teacher, $courseId);

        $removed = array_flip($input['removed']);
        foreach ($input['students'] as $i => $row) {
            if (isset($removed[$row['google_user_id']])) {
                throw ValidationException::withMessages(["students.{$i}.google_user_id" => ['บัญชีนี้อยู่ทั้งในรายชื่อที่นำเข้าและที่เอาออก']]);
            }
        }

        $byId = array_column($accounts, null, 'google_user_id');
        $rows = [];
        $chosen = [];
        $used = [];
        foreach ($input['students'] as $row) {
            $account = $byId[$row['google_user_id']] ?? null;
            if ($account === null) {
                continue; // left the course after the preview
            }
            $rows[] = ['account' => $account, 'student_number' => (int) $row['student_number'], 'student_id' => $row['student_id'] ?? null];
            $chosen[$row['google_user_id']] = true;
            if (($row['student_id'] ?? null) !== null) {
                $used[(int) $row['student_id']] = true;
            }
        }
        $next = max([0, ...array_column($rows, 'student_number')]) + 1;
        $late = array_values(array_filter($accounts, fn (array $a) => ! isset($chosen[$a['google_user_id']]) && ! isset($removed[$a['google_user_id']])));
        $lateMatches = $this->matcher->match((int) $teacher->school_id, $late, byName: false);
        foreach (self::sorted($late) as $account) {
            $studentId = $lateMatches[$account['google_user_id']]['student_id'] ?? null;
            if ($studentId !== null && isset($used[$studentId])) {
                $studentId = null;
            }
            if ($studentId !== null) {
                $used[$studentId] = true;
            }
            $rows[] = ['account' => $account, 'student_number' => $next++, 'student_id' => $studentId];
        }
        if ($next - 1 > self::MAX_STUDENT_NUMBER) {
            throw ValidationException::withMessages(['students' => ['เลขที่ต้องไม่เกิน '.self::MAX_STUDENT_NUMBER]]);
        }

        $result = self::underCourseLock($course['course_id'], fn () => DB::transaction(function () use ($teacher, $input, $course, $rows, $byId) {
            // The check above ran before the Google calls; run it again under the lock.
            self::assertNotLinked($course['course_id']);

            $classroom = Classroom::create([
                'school_id' => $teacher->school_id,
                'teacher_id' => $teacher->id,
                'name' => $input['name'],
                'grade_level' => $input['grade_level'],
                'academic_year' => $input['academic_year'],
                'class_code' => ClassCodeGenerator::unique(),
            ]);

            $created = [];
            foreach (array_chunk($rows, self::CHUNK) as $chunk) {
                $created = [...$created, ...$this->enroller->enroll($classroom, array_map(fn (array $r) => $r['student_id'] !== null
                    ? ['student_id' => $r['student_id'], 'student_number' => $r['student_number']]
                    : ['name' => GoogleRoster::studentName($r['account']), 'student_number' => $r['student_number']], $chunk))];
            }

            ClassroomGoogleLink::create([
                'classroom_id' => $classroom->id,
                'course_id' => $course['course_id'],
                'course_name' => mb_substr($course['name'] !== '' ? $course['name'] : $course['course_id'], 0, 255),
                'owner_user_id' => $teacher->id,
                'linked_at' => now(),
                'roster_synced_at' => now(),
            ]);

            foreach ($rows as $i => $row) {
                ClassroomStudent::query()
                    ->where('classroom_id', $classroom->id)
                    ->where('student_id', $created[$i]['student']->id)
                    ->update(['google_user_id' => $row['account']['google_user_id'], 'google_email' => $row['account']['email']]);
            }

            foreach (array_keys(array_flip($input['removed'])) as $googleUserId) {
                $googleUserId = (string) $googleUserId;
                ClassroomGoogleIgnoredUser::query()->insert([
                    'classroom_id' => $classroom->id,
                    'google_user_id' => $googleUserId,
                    'name' => isset($byId[$googleUserId]) ? GoogleRoster::studentName($byId[$googleUserId]) : $googleUserId,
                    'created_at' => now(),
                ]);
            }

            return ['classroom' => $classroom, 'students' => $created];
        }));

        Log::info('google.classroom_imported', [
            'classroom_id' => $result['classroom']->id,
            'students' => count($result['students']),
            'existing' => count(array_filter($result['students'], fn (array $r) => $r['existing'])),
            'removed' => count($input['removed']),
        ]);

        return $result;
    }

    /**
     * POST /google/courses/{course_id}/link-existing {classroom_id, app_course_id}
     * (DESIGN §24.10): the course becomes the teacher's course of an existing
     * open classroom of the school instead of a new classroom.
     *
     * - The homeroom teacher of the classroom: app_course_id (optional) is
     *   bound to the classroom, the course linked, and the roster synced as
     *   the homeroom teacher's course (existing students matched or
     *   enrolled, new ones added with their PINs in `roster`).
     * - A subject teacher whose app_course_id is bound to the classroom
     *   already: the course is linked and the roster matched (never added).
     * - Anyone else: a course request with origin classroom_import; the
     *   course is linked when the homeroom teacher approves.
     *
     * A Google error of the roster sync after the link does not undo the
     * link: `roster` is null and `roster_error` says why (sync again later).
     *
     * @return array{status: 'linked', classroom: Classroom, link: ClassroomGoogleLink, roster: array<string, mixed>|null, roster_error: array{code: string, message: string}|null}|array{status: 'requested', request: ClassroomCourseRequest}
     *
     * @throws ApiException 404 classroom, 409 classroom_closed | course_already_linked |
     *                      course_link_busy | classroom_has_google_posts | request_pending |
     *                      course_already_in_classroom, 422 course_id | app_course_id
     */
    public function linkExisting(User $teacher, string $courseId, int $classroomId, ?int $appCourseId): array
    {
        $classroom = Classroom::query()->where('school_id', $teacher->school_id)->findOrFail($classroomId);
        ClosedClassrooms::assertOpen($classroom);
        self::assertNotLinked($courseId);
        $homeroom = ClassroomAccess::homeroomOf($teacher, $classroom);
        $appCourse = null;
        if ($appCourseId !== null) {
            $appCourse = Course::query()->where('created_by', $teacher->id)->where('school_id', $teacher->school_id)->find($appCourseId)
                ?? throw ClassroomGoogleLinks::invalidAppCourse('ไม่พบรายวิชานี้ในรายวิชาของคุณ');
        } elseif (! $homeroom) {
            throw ClassroomGoogleLinks::invalidAppCourse('เลือกรายวิชาของคุณที่จะสอนห้องนี้');
        }

        $course = collect($this->accounts->call($teacher, fn (GoogleApi $api) => $api->teacherCourses()))->firstWhere('course_id', $courseId);
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => ['ไม่พบคอร์สนี้ในคอร์สที่คุณสอนอยู่ (ACTIVE) ใน Google Classroom']]);
        }

        $bound = $appCourse !== null && CourseRequests::bound($classroom->id, $appCourse->id);
        if (! $homeroom && ! $bound) {
            /** @var Course $appCourse */
            $request = $this->courseRequests->requestImport($teacher, $classroom, $appCourse, $course);
            Log::info('google.link_existing_requested', ['classroom_id' => $classroom->id, 'request_id' => $request->id]);

            return ['status' => 'requested', 'request' => $request];
        }

        if ($homeroom && $appCourse !== null && ! $bound) {
            $this->courseRequests->bindOwn($teacher, $classroom, $appCourse);
        }
        $link = self::underCourseLock($course['course_id'], function () use ($classroom, $teacher, $course, $appCourse) {
            self::assertNotLinked($course['course_id']);

            return ClassroomGoogleLinks::put($classroom, $teacher, $course, $appCourse?->id ?? ClassroomGoogleLinks::appCourseFor($classroom, $teacher, null));
        });
        Log::info('google.link_existing_linked', ['classroom_id' => $classroom->id, 'link_id' => $link->id, 'homeroom' => $homeroom]);

        $roster = null;
        $error = null;
        try {
            $roster = $this->rosterSync->syncLink($teacher, $classroom, $link);
        } catch (ApiException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
        } catch (ValidationException $e) {
            $error = ['code' => 'validation_failed', 'message' => $e->getMessage()];
        }

        return ['status' => 'linked', 'classroom' => $classroom, 'link' => $link, 'roster' => $roster, 'roster_error' => $error];
    }

    /**
     * Every student_id of the import is an active, unmerged student of the
     * teacher's school (StudentEnroller checks again inside the transaction).
     *
     * @param  list<array{google_user_id: string, student_number: int, student_id: int|null}>  $rows
     *
     * @throws ApiException 422 student_not_in_school
     */
    private function assertStudentsOfSchool(User $teacher, array $rows): void
    {
        $ids = array_values(array_filter(array_column($rows, 'student_id'), fn ($id) => $id !== null));
        if ($ids === []) {
            return;
        }
        $students = User::query()->whereIn('id', $ids)->get()->keyBy('id');
        $errors = [];
        foreach ($rows as $i => $row) {
            if (($row['student_id'] ?? null) === null) {
                continue;
            }
            $student = $students->get($row['student_id']);
            if ($student === null || ! $student->isStudent() || ! $student->isActive() || $student->isMerged() || (int) $student->school_id !== (int) $teacher->school_id) {
                $errors["students.{$i}.student_id"] = ['ไม่พบนักเรียนคนนี้ในโรงเรียน'];
            }
        }
        if ($errors !== []) {
            throw new ApiException('ไม่พบนักเรียนบางคนในโรงเรียน', 'student_not_in_school', 422, $errors);
        }
    }

    /** ปีการศึกษา: the current Buddhist year in Asia/Bangkok. */
    public static function currentAcademicYear(): int
    {
        return (int) now('Asia/Bangkok')->year + 543;
    }

    /**
     * @param  array{course_id: string, name: string, section: string|null}  $course
     */
    public static function suggestedName(array $course): string
    {
        $name = trim($course['name'].' '.($course['section'] ?? ''));
        // A section already in the course name ("คณิต ม.1/1" + "1/1") is not repeated.
        if ($course['section'] !== null && str_contains($course['name'], $course['section'])) {
            $name = trim($course['name']);
        }

        return mb_substr($name !== '' ? $name : $course['course_id'], 0, 100);
    }

    /**
     * Runs $fn while no other import or link of the same course runs. A row
     * lock cannot do this: before the first link there is no row to lock,
     * and on MariaDB two FOR UPDATE reads of the missing course_id take gap
     * locks that do not block each other, so both inserts deadlock (a 500).
     * The cache lock (database driver) is shared by every web request.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     *
     * @throws ApiException 409 course_link_busy when the other one takes too long
     */
    public static function underCourseLock(string $courseId, Closure $fn): mixed
    {
        try {
            return Cache::lock('google-course-link:'.$courseId, self::COURSE_LOCK_SECONDS)->block((int) config('eduvision.classroom_sync.link_lock_wait_seconds', 20), $fn);
        } catch (LockTimeoutException) {
            throw new ApiException('กำลังผูกคอร์สนี้กับห้องเรียนอยู่ รอสักครู่แล้วลองอีกครั้ง', 'course_link_busy', 409);
        }
    }

    /**
     * @throws ApiException 409 course_already_linked
     */
    public static function assertNotLinked(string $courseId): void
    {
        if (ClassroomGoogleLink::query()->where('course_id', $courseId)->exists()) {
            throw new ApiException('คอร์สนี้ผูกกับห้องเรียนในระบบแล้ว นำเข้าซ้ำไม่ได้', 'course_already_linked', 409);
        }
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts
     * @return list<array{google_user_id: string, name: string, email: string|null}>
     */
    private static function sorted(array $accounts): array
    {
        $named = array_map(fn (array $a) => [...$a, 'sort_name' => GoogleRoster::studentName($a)], $accounts);

        return array_map(
            fn (array $a) => ['google_user_id' => $a['google_user_id'], 'name' => $a['name'], 'email' => $a['email']],
            ThaiNameSorter::sort($named, 'sort_name', 'google_user_id'),
        );
    }

    /**
     * @return array{0: array{course_id: string, name: string, section: string|null}, 1: list<array{google_user_id: string, name: string, email: string|null}>}
     *
     * @throws ValidationException the course is not an ACTIVE course the teacher teaches
     */
    private function courseAndRoster(User $teacher, string $courseId): array
    {
        $course = collect($this->accounts->call($teacher, fn (GoogleApi $api) => $api->teacherCourses()))->firstWhere('course_id', $courseId);
        if ($course === null) {
            throw ValidationException::withMessages(['course_id' => ['ไม่พบคอร์สนี้ในคอร์สที่คุณสอนอยู่ (ACTIVE) ใน Google Classroom']]);
        }
        $accounts = $this->accounts->call($teacher, fn (GoogleApi $api) => $api->courseStudents($courseId), GoogleRoster::COURSE_GONE);

        return [$course, $accounts];
    }
}
