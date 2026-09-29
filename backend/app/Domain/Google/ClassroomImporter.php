<?php

namespace App\Domain\Google;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\GradeLevelGuesser;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Classrooms\ThaiNameSorter;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomGoogleIgnoredUser;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\User;
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
 */
final class ClassroomImporter
{
    /** StudentEnroller is called for at most this many students at a time. */
    public const CHUNK = 100;

    /** classroom_students.student_number is a TINYINT UNSIGNED. */
    public const MAX_STUDENT_NUMBER = 255;

    public function __construct(
        private readonly GoogleAccounts $accounts,
        private readonly StudentEnroller $enroller,
    ) {}

    /**
     * GET /google/courses/{course_id}/import-preview.
     *
     * @return array{course_id: string, name: string, section: string|null, suggested_name: string, grade_level_guess: int|null, academic_year: int, students: list<array{google_user_id: string, name: string, email: string|null, proposed_number: int}>}
     */
    public function preview(User $teacher, string $courseId): array
    {
        self::assertNotLinked($courseId);
        [$course, $accounts] = $this->courseAndRoster($teacher, $courseId);

        $students = [];
        foreach (self::sorted($accounts) as $i => $account) {
            $students[] = [
                'google_user_id' => $account['google_user_id'],
                'name' => GoogleRoster::studentName($account),
                'email' => $account['email'],
                'proposed_number' => $i + 1,
            ];
        }

        return [
            'course_id' => $course['course_id'],
            'name' => $course['name'],
            'section' => $course['section'],
            'suggested_name' => self::suggestedName($course),
            'grade_level_guess' => GradeLevelGuesser::guess($course['name'], $course['section']),
            'academic_year' => self::currentAcademicYear(),
            'students' => $students,
        ];
    }

    /**
     * POST /classrooms/import-google.
     *
     * @param  array{course_id: string, name: string, grade_level: int, academic_year: int, students: list<array{google_user_id: string, student_number: int}>, removed: list<string>}  $input
     * @return array{classroom: Classroom, students: list<array{student: User, student_number: int, pin: string}>}
     *
     * @throws ApiException 409 course_already_linked
     * @throws ValidationException an account both kept and removed, too many students
     */
    public function import(User $teacher, array $input): array
    {
        $courseId = $input['course_id'];
        self::assertNotLinked($courseId);
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
        foreach ($input['students'] as $row) {
            $account = $byId[$row['google_user_id']] ?? null;
            if ($account === null) {
                continue; // left the course after the preview
            }
            $rows[] = ['account' => $account, 'student_number' => (int) $row['student_number']];
            $chosen[$row['google_user_id']] = true;
        }
        $next = max([0, ...array_column($rows, 'student_number')]) + 1;
        $late = array_values(array_filter($accounts, fn (array $a) => ! isset($chosen[$a['google_user_id']]) && ! isset($removed[$a['google_user_id']])));
        foreach (self::sorted($late) as $account) {
            $rows[] = ['account' => $account, 'student_number' => $next++];
        }
        if ($next - 1 > self::MAX_STUDENT_NUMBER) {
            throw ValidationException::withMessages(['students' => ['เลขที่ต้องไม่เกิน '.self::MAX_STUDENT_NUMBER]]);
        }

        $result = DB::transaction(function () use ($teacher, $input, $course, $rows, $byId) {
            // The check above ran before the Google calls; run it again under the lock.
            ClassroomGoogleLink::query()->where('course_id', $course['course_id'])->lockForUpdate()->get();
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
                $created = [...$created, ...$this->enroller->enroll($classroom, array_map(fn (array $r) => [
                    'name' => GoogleRoster::studentName($r['account']),
                    'student_number' => $r['student_number'],
                ], $chunk))];
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
        });

        Log::info('google.classroom_imported', [
            'classroom_id' => $result['classroom']->id,
            'students' => count($result['students']),
            'removed' => count($input['removed']),
        ]);

        return $result;
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
