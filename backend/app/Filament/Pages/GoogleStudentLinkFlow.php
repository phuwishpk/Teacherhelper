<?php

namespace App\Filament\Pages;

use App\Domain\Auth\Google\StudentRosterLink;
use App\Domain\Google\GoogleEventLabels;
use App\Domain\Google\SchoolStudentMatcher;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;

/**
 * "ทดสอบการเชื่อม Google ของนักเรียน" (DESIGN §32): the two ways a student's
 * Google account reaches their Krucheck account automatically, drawn as a
 * flow, and a dry run for one e-mail. The dry run calls the same
 * StudentRosterLink and SchoolStudentMatcher as sign-in and the roster sync,
 * and writes nothing (no link, no google_events row).
 */
class GoogleStudentLinkFlow extends Page
{
    protected string $view = 'filament.google-student-link-flow';

    protected static ?string $slug = 'google-student-link';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLink;

    protected static ?string $navigationLabel = 'ทดสอบการเชื่อม Google';

    protected static ?string $title = 'ทดสอบการเชื่อม Google ของนักเรียน';

    protected static ?int $navigationSort = 46;

    public const MATCH_LABELS = [
        SchoolStudentMatcher::BY_GOOGLE => 'บัญชี Google ที่นักเรียนใช้เข้าสู่ระบบ',
        SchoolStudentMatcher::BY_CLASSROOM_USER => 'บัญชี Classroom เดียวกันในห้องอื่นของพื้นที่นี้',
        SchoolStudentMatcher::BY_EMAIL => 'อีเมลเดียวกันในห้องอื่นของพื้นที่นี้',
    ];

    public const ROSTER_LABELS = [
        StudentRosterLink::OK => 'ผ่านทุกข้อ: ระบบเชื่อมบัญชีให้เองและเข้าสู่ระบบทันที',
        StudentRosterLink::NO_SUB_ROW => 'ไม่มีห้องใดที่มีบัญชี Classroom นี้ (ยังไม่ได้นำเข้าหรือซิงก์รายชื่อ หรือนักเรียนเพิ่งเข้าคอร์ส)',
        StudentRosterLink::SEVERAL_STUDENTS => 'บัญชี Classroom หรืออีเมลนี้อยู่กับนักเรียนมากกว่าหนึ่งคน (รวมบัญชีที่ซ้ำก่อน)',
        StudentRosterLink::EMAIL_NOT_ON_SUB_ROW => 'แถวที่มีบัญชี Classroom นี้ใช้อีเมลอื่น (อีเมลในรายชื่อต้องตรงกับอีเมลที่เข้าสู่ระบบ)',
        StudentRosterLink::STUDENT_NOT_ACTIVE => 'บัญชีนักเรียนไม่ได้ใช้งานอยู่ (ปิด ลบ หรือถูกรวมแล้ว)',
        StudentRosterLink::OTHER_IDENTITY => 'นักเรียนคนนี้เชื่อมกับบัญชี Google อื่นอยู่แล้ว',
    ];

    /** The Google e-mail to try. */
    public string $email = '';

    /** The Classroom userId (= Google sign-in `sub`); empty = take it from the data. */
    public string $sub = '';

    /** One more workspace to run the import match for, besides the ones the account is in. */
    public ?int $schoolId = null;

    public bool $checked = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->isAdmin();
    }

    public function check(): void
    {
        $this->validate([
            'email' => ['required', 'email', 'max:255'],
            'sub' => ['nullable', 'string', 'max:64'],
            'schoolId' => ['nullable', 'integer'],
        ], attributes: ['email' => 'อีเมล', 'sub' => 'Google user ID']);
        $this->checked = true;
    }

    /** @return array<int, string> */
    public function getSchoolOptions(): array
    {
        return School::query()->orderBy('name')->pluck('name', 'id')->all();
    }

    /**
     * Everything the page shows under "ผลการทดสอบ"; null before the first check.
     *
     * @return array<string, mixed>|null
     */
    public function getTrace(): ?array
    {
        $email = mb_strtolower(trim($this->email));
        if (! $this->checked || $email === '') {
            return null;
        }

        $identityByEmail = UserGoogleIdentity::query()->with('user')->whereRaw('LOWER(email) = ?', [$email])->first();
        $rows = $this->rosterRows(trim($this->sub), $email);
        [$sub, $subSource] = $this->resolveSub(trim($this->sub), $identityByEmail, $rows);
        if ($sub !== null && trim($this->sub) === '') {
            $rows = $this->rosterRows($sub, $email);
        }

        $identity = $sub === null ? null : UserGoogleIdentity::query()->with('user')->where('google_sub', $sub)->first();
        $roster = $sub === null
            ? ['result' => StudentRosterLink::NO_SUB_ROW, 'student' => null, 'student_ids' => []]
            : app(StudentRosterLink::class)->check($sub, $email);

        return [
            'email' => $email,
            'sub' => $sub,
            'sub_source' => $subSource,
            'rows' => $rows,
            'identity' => $identity === null ? null : self::identityPayload($identity),
            'roster' => [
                'result' => $roster['result'],
                'label' => self::ROSTER_LABELS[$roster['result']] ?? $roster['result'],
                'student' => $roster['student'] === null ? null : ['id' => $roster['student']->id, 'name' => $roster['student']->name],
                'several' => count($roster['student_ids']) > 1
                    ? User::query()->whereIn('id', $roster['student_ids'])->orderBy('id')->pluck('name')->all()
                    : [],
            ],
            'workspaces' => $this->importMatches($sub, $email, $rows, $identity),
        ];
    }

    /**
     * Roster rows with the userId or the e-mail, with their classroom, workspace and student.
     *
     * @return list<array<string, mixed>>
     */
    private function rosterRows(string $sub, string $email): array
    {
        return DB::table('classroom_students')
            ->join('classrooms', 'classrooms.id', '=', 'classroom_students.classroom_id')
            ->join('users', 'users.id', '=', 'classroom_students.student_id')
            ->leftJoin('schools', 'schools.id', '=', 'classrooms.school_id')
            ->where(function ($q) use ($sub, $email) {
                $q->whereRaw('LOWER(classroom_students.google_email) = ?', [$email]);
                if ($sub !== '') {
                    $q->orWhere('classroom_students.google_user_id', $sub);
                }
            })
            ->orderBy('classrooms.school_id')
            ->orderBy('classrooms.name')
            ->limit(50)
            ->get([
                'classrooms.id as classroom_id', 'classrooms.name as classroom', 'classrooms.academic_year',
                'classrooms.school_id', 'schools.name as school',
                'users.id as student_id', 'users.name as student',
                'classroom_students.student_number', 'classroom_students.google_user_id',
                'classroom_students.google_email', 'classroom_students.left_course_at',
            ])
            ->map(fn ($r) => (array) $r)
            ->all();
    }

    /**
     * The userId to try: typed, else the linked sign-in with the e-mail,
     * else the one userId the roster rows with the e-mail carry.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{0: string|null, 1: string}
     */
    private function resolveSub(string $typed, ?UserGoogleIdentity $identityByEmail, array $rows): array
    {
        if ($typed !== '') {
            return [$typed, 'typed'];
        }
        if ($identityByEmail !== null) {
            return [(string) $identityByEmail->google_sub, 'identity'];
        }
        $subs = array_values(array_unique(array_filter(array_map(fn (array $r) => $r['google_user_id'], $rows))));

        return count($subs) === 1 ? [(string) $subs[0], 'roster'] : [null, count($subs) > 1 ? 'several' : 'none'];
    }

    /**
     * SchoolStudentMatcher passes 1-3 (what an import or roster sync uses
     * without asking) in every workspace the account shows up in, plus the
     * chosen one.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, name: string, student: string|null, matched_by: string|null}>
     */
    private function importMatches(?string $sub, string $email, array $rows, ?UserGoogleIdentity $identity): array
    {
        $ids = array_map(fn (array $r) => (int) $r['school_id'], $rows);
        if ($identity?->user !== null) {
            $ids[] = (int) $identity->user->school_id;
        }
        if ($this->schoolId !== null) {
            $ids[] = (int) $this->schoolId;
        }
        $ids = array_values(array_unique($ids));
        $names = School::query()->whereIn('id', $ids)->pluck('name', 'id');
        $account = ['google_user_id' => $sub ?? '', 'name' => '', 'email' => $email];

        $result = [];
        foreach ($ids as $id) {
            $match = app(SchoolStudentMatcher::class)->match($id, [$account], byName: false)[$account['google_user_id']] ?? null;
            $result[] = [
                'id' => $id,
                'name' => (string) ($names[$id] ?? "#{$id}"),
                'student' => $match === null ? null : User::query()->whereKey($match['student_id'])->value('name'),
                'matched_by' => $match === null ? null : (self::MATCH_LABELS[$match['matched_by']] ?? $match['matched_by']),
            ];
        }

        return $result;
    }

    /** @return array{user_id: int, name: string|null, role: string|null, active: bool, linked_via: string|null, email: string|null} */
    private static function identityPayload(UserGoogleIdentity $identity): array
    {
        $user = $identity->user;

        return [
            'user_id' => (int) $identity->user_id,
            'name' => $user?->name,
            'role' => $user?->role,
            'active' => $user !== null && $user->isActive() && ! $user->isMerged(),
            'linked_via' => GoogleEventLabels::VIA[$identity->linked_via] ?? $identity->linked_via,
            'email' => $identity->email,
        ];
    }
}
