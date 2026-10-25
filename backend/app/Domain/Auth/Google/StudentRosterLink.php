<?php

namespace App\Domain\Auth\Google;

use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Support\Facades\DB;

/**
 * §24.9.3 step 4: whether a student's first Google sign-in links from the
 * Classroom roster. A roster row (classroom_students) must have both the
 * token's `sub` (Classroom userId) and its verified e-mail, every row with
 * either belongs to that one student, and the student is active, unmerged
 * and without another Google account. GoogleSignIn links on `ok`; the admin
 * page "ทดสอบการเชื่อม Google ของนักเรียน" (§32) shows the same check
 * without linking. Reads only.
 */
final class StudentRosterLink
{
    public const OK = 'ok';

    /** No roster row has the Classroom userId (not synced yet, or not in a linked course). */
    public const NO_SUB_ROW = 'no_sub_row';

    /** Rows with the userId or the e-mail belong to more than one student. */
    public const SEVERAL_STUDENTS = 'several_students';

    /** No row with the userId has the e-mail too. */
    public const EMAIL_NOT_ON_SUB_ROW = 'email_not_on_sub_row';

    /** The student is a non-student, not active, deleted or merged. */
    public const STUDENT_NOT_ACTIVE = 'student_not_active';

    /** The student is linked to another Google account already. */
    public const OTHER_IDENTITY = 'other_identity';

    /**
     * @param  string  $email  lower case, as VerifiedGoogleIdentity::$email
     * @return array{result: string, student: User|null, student_ids: list<int>}
     */
    public function check(string $sub, string $email): array
    {
        $bySub = DB::table('classroom_students')->where('google_user_id', $sub)->get(['student_id', 'google_email']);
        if ($bySub->isEmpty()) {
            return self::result(self::NO_SUB_ROW, null, []);
        }
        $byEmail = DB::table('classroom_students')->whereRaw('LOWER(google_email) = ?', [$email])->pluck('student_id');

        $ids = $bySub->pluck('student_id')->merge($byEmail)->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (count($ids) !== 1) {
            return self::result(self::SEVERAL_STUDENTS, null, $ids);
        }
        $student = User::query()->with('school')->find($ids[0]);
        if (! $bySub->contains(fn ($row) => mb_strtolower(trim((string) $row->google_email)) === $email)) {
            return self::result(self::EMAIL_NOT_ON_SUB_ROW, $student, $ids);
        }
        if ($student === null || ! $student->isStudent() || ! $student->isActive() || $student->isMerged()) {
            return self::result(self::STUDENT_NOT_ACTIVE, $student, $ids);
        }
        if (UserGoogleIdentity::query()->where('user_id', $student->id)->exists()) {
            return self::result(self::OTHER_IDENTITY, $student, $ids);
        }

        return self::result(self::OK, $student, $ids);
    }

    /**
     * @param  list<int>  $ids
     * @return array{result: string, student: User|null, student_ids: list<int>}
     */
    private static function result(string $result, ?User $student, array $ids): array
    {
        return ['result' => $result, 'student' => $student, 'student_ids' => $ids];
    }
}
