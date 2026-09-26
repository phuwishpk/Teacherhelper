<?php

namespace App\Domain\Google;

use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomStudent;
use App\Models\ClassroomSubmissionImport;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Matching the students of a linked Google Classroom course to the students
 * of the classroom (DESIGN §18.6 GET/PUT /classrooms/{id}/google-roster).
 * The match lives on classroom_students.google_user_id / google_email; the
 * unique key (classroom_id, google_user_id) keeps it one student per account.
 */
final class GoogleRoster
{
    public const COURSE_GONE = 'ไม่พบคอร์สที่ผูกไว้ใน Google Classroom แล้ว (อาจถูกลบหรือเก็บถาวร) ยกเลิกการผูกแล้วผูกคอร์สใหม่';

    public function __construct(private readonly GoogleAccounts $accounts) {}

    /**
     * @return list<array{google_user_id: string, name: string, email: string|null, suggested_student_id: int|null, matched_student_id: int|null}>
     */
    public function rows(User $teacher, Classroom $classroom, ClassroomGoogleLink $link): array
    {
        return $this->build($classroom, $this->courseStudents($teacher, $link));
    }

    /**
     * Applies the teacher's pairs. Accounts not in $matches keep their match.
     *
     * @param  list<array{google_user_id: string, student_id: int|null}>  $matches
     * @return list<array<string, mixed>> the roster after saving
     *
     * @throws ValidationException an account not in the course, a student not in the classroom
     */
    public function save(User $teacher, Classroom $classroom, ClassroomGoogleLink $link, array $matches): array
    {
        $accounts = $this->courseStudents($teacher, $link);
        $emails = array_column($accounts, 'email', 'google_user_id');
        $enrolled = ClassroomStudent::query()->where('classroom_id', $classroom->id)->pluck('student_id')->map(fn ($id) => (int) $id)->flip();

        $errors = [];
        foreach ($matches as $i => $match) {
            if (! array_key_exists($match['google_user_id'], $emails)) {
                $errors["matches.{$i}.google_user_id"][] = 'บัญชีนี้ไม่ได้อยู่ในคอร์สที่ผูกไว้';
            }
            if ($match['student_id'] !== null && ! isset($enrolled[$match['student_id']])) {
                $errors["matches.{$i}.student_id"][] = 'นักเรียนคนนี้ไม่ได้อยู่ในห้องเรียนนี้';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($classroom, $matches, $emails) {
            $googleIds = array_column($matches, 'google_user_id');
            $studentIds = array_values(array_filter(array_column($matches, 'student_id'), fn ($id) => $id !== null));

            // Clear first, so moving an account from one student to another never
            // trips the unique key half way.
            ClassroomStudent::query()
                ->where('classroom_id', $classroom->id)
                ->where(fn ($q) => $q->whereIn('google_user_id', $googleIds)->orWhereIn('student_id', $studentIds))
                ->update(['google_user_id' => null, 'google_email' => null]);

            foreach ($matches as $match) {
                if ($match['student_id'] === null) {
                    continue;
                }
                ClassroomStudent::query()
                    ->where('classroom_id', $classroom->id)
                    ->where('student_id', $match['student_id'])
                    ->update(['google_user_id' => $match['google_user_id'], 'google_email' => $emails[$match['google_user_id']] ?? null]);
            }

            // Submissions not scanned or graded yet follow the new pairs (the
            // student of a scanned one is whoever the scans were filed under).
            $assignmentIds = $classroom->assignments()->pluck('id');
            foreach ($matches as $match) {
                ClassroomSubmissionImport::query()
                    ->whereIn('assignment_id', $assignmentIds)
                    ->where('google_user_id', $match['google_user_id'])
                    ->whereIn('state', GoogleSubmissionSync::FOLLOWS_ROSTER_STATES)
                    ->update(['student_id' => $match['student_id']]);
            }
        });

        return $this->build($classroom, $accounts);
    }

    /**
     * @return list<array{google_user_id: string, name: string, email: string|null}>
     */
    private function courseStudents(User $teacher, ClassroomGoogleLink $link): array
    {
        return $this->accounts->call($teacher, fn (GoogleApi $api) => $api->courseStudents($link->course_id), self::COURSE_GONE);
    }

    /**
     * @param  list<array{google_user_id: string, name: string, email: string|null}>  $accounts
     * @return list<array{google_user_id: string, name: string, email: string|null, suggested_student_id: int|null, matched_student_id: int|null}>
     */
    private function build(Classroom $classroom, array $accounts): array
    {
        $members = ClassroomStudent::query()
            ->join('users', 'users.id', '=', 'classroom_students.student_id')
            ->where('classroom_students.classroom_id', $classroom->id)
            ->get(['classroom_students.student_id', 'classroom_students.google_user_id', 'users.name']);

        $inCourse = array_flip(array_column($accounts, 'google_user_id'));
        $matched = [];
        $candidates = [];
        foreach ($members as $member) {
            $googleId = $member->google_user_id;
            if ($googleId !== null && isset($inCourse[$googleId])) {
                $matched[$googleId] = (int) $member->student_id;
            } else {
                $candidates[] = ['id' => (int) $member->student_id, 'name' => (string) $member->name];
            }
        }

        $unmatched = array_values(array_filter($accounts, fn (array $a) => ! isset($matched[$a['google_user_id']])));
        $suggested = RosterMatcher::suggest($unmatched, $candidates);

        $rows = [];
        foreach ($accounts as $account) {
            $id = $account['google_user_id'];
            $rows[] = [
                'google_user_id' => $id,
                'name' => $account['name'],
                'email' => $account['email'],
                'suggested_student_id' => $matched[$id] ?? $suggested[$id] ?? null,
                'matched_student_id' => $matched[$id] ?? null,
            ];
        }
        usort($rows, fn (array $a, array $b) => strcmp(NameNormalizer::key($a['name']), NameNormalizer::key($b['name'])));

        return $rows;
    }

    /**
     * The link of a classroom, or 422 classroom_not_linked.
     */
    public static function linkOf(Classroom $classroom): ClassroomGoogleLink
    {
        $link = $classroom->googleLink()->first();
        if ($link === null) {
            throw new ApiException('ห้องเรียนนี้ยังไม่ได้ผูกกับ Google Classroom ผูกที่หน้าห้องเรียนก่อน', 'classroom_not_linked', 422);
        }

        return $link;
    }
}
