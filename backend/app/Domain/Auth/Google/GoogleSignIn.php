<?php

namespace App\Domain\Auth\Google;

use App\Domain\Classrooms\ClassroomJoiner;
use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\StudentAuthenticator;
use App\Domain\Students\StudentUsernames;
use App\Domain\Teachers\Workspaces;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Jobs\ClassroomSyncJob;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;

/**
 * Google sign-in for every role (DESIGN §24.9, §29.13.2): signing in with a
 * linked Google account, the automatic links (a teacher's matching e-mail,
 * a student's Classroom roster entry), the explicit links and unlinking.
 * An unknown staff account becomes an active teacher with a workspace of
 * its own at once (§29.1); an unknown student gets 404 google_not_linked
 * with a link ticket. There are no per-school domain or student switches
 * any more. Each outcome is logged as `google_signin` with the event, the
 * result and the user id only (no e-mail, no token).
 */
final class GoogleSignIn
{
    public const INTENT_STAFF = 'staff';

    public const INTENT_STUDENT = 'student';

    public function __construct(
        private readonly GoogleSignInTickets $tickets,
        private readonly StudentAuthenticator $students,
        private readonly CredentialIssuer $issuer,
    ) {}

    /**
     * POST /auth/google (and the browser flow's ticket): a token for the user
     * linked to $google, or the first sign-in of §24.9.3 steps 3-4.
     *
     * @return array{token: NewAccessToken, user: User}
     *
     * @throws ApiException 404 google_not_linked, 403 account_not_active
     */
    public function signIn(VerifiedGoogleIdentity $google, string $intent, ?string $deviceName = null): array
    {
        $identity = UserGoogleIdentity::query()->with('user.school')->where('google_sub', $google->sub)->first();
        if ($identity !== null && $identity->user !== null) {
            return $this->complete($identity, $google, $deviceName);
        }

        return $intent === self::INTENT_STUDENT
            ? $this->firstStudentSignIn($google, $deviceName)
            : $this->firstStaffSignIn($google, $deviceName);
    }

    /**
     * The student's first sign-in confirmed with PIN or QR (§24.9.5): links
     * the Google account of the link ticket to $student (already checked by
     * StudentAuthenticator) and signs them in.
     *
     * @return array{token: NewAccessToken, user: User}
     *
     * @throws ApiException 422 link_ticket_invalid, 403 / 409 of link()
     */
    public function linkStudentWithTicket(User $student, VerifiedGoogleIdentity $google, string $ticket, ?string $deviceName = null): array
    {
        $this->assertCanLink($student, $google);
        if ($this->tickets->consumeLink($ticket) === null) {
            throw GoogleSignInErrors::linkTicketInvalid();
        }
        $identity = $this->link($student, $google, UserGoogleIdentity::VIA_PIN_CONFIRM, $student);

        return $this->complete($identity->setRelation('user', $student), $google, $deviceName);
    }

    /**
     * POST /auth/google/join (DESIGN §29.13.3): the Google account of a link
     * ticket is unknown, but the pupil holds a class code, so it becomes a
     * student account at once: name from Google, no e-mail or password of
     * its own (a random one nobody knows, never forced to change), a
     * generated username, the classroom owner's workspace as its origin, and
     * the Google account linked as `google_signup`. The classroom itself is
     * joined afterwards with POST /student/join. A Google account linked
     * meanwhile simply signs in.
     *
     * @return array{token: NewAccessToken, user: User}
     *
     * @throws ApiException 409 join_closed, 422 link_ticket_invalid
     */
    public function signUpStudent(VerifiedGoogleIdentity $google, Classroom $classroom, string $ticket, ?string $deviceName = null): array
    {
        $linked = UserGoogleIdentity::query()->with('user.school')->where('google_sub', $google->sub)->first();
        if ($linked !== null && $linked->user !== null) {
            $this->tickets->consumeLink($ticket);

            return $this->complete($linked, $google, $deviceName);
        }
        if (! ClassroomJoiner::accepts($classroom)) {
            self::log('signup_student', 'join_closed');

            throw new ApiException('ห้องนี้ปิดรับสมาชิกแล้ว ติดต่อครูประจำวิชาเพื่อให้เพิ่มชื่อ', 'join_closed', 409);
        }
        if ($this->tickets->consumeLink($ticket) === null) {
            throw GoogleSignInErrors::linkTicketInvalid();
        }

        $identity = DB::transaction(function () use ($google, $classroom) {
            $student = User::create([
                'school_id' => $classroom->school_id,
                'role' => User::ROLE_STUDENT,
                'name' => self::signUpName($google),
                'email' => null,
                'password' => null,
                'status' => User::STATUS_ACTIVE,
                'username' => StudentUsernames::generate(null),
            ]);
            $this->issuer->create($student);
            $this->issuer->setPassword($student, Str::random(40));

            return $this->link($student, $google, UserGoogleIdentity::VIA_GOOGLE_SIGNUP, null)->setRelation('user', $student->setRelation('school', $classroom->school));
        });
        self::log('signup_student', 'ok', (int) $identity->user_id, UserGoogleIdentity::VIA_GOOGLE_SIGNUP);

        return $this->complete($identity, $google, $deviceName);
    }

    /**
     * Links $google to $user after the checks of §24.9.5: the Google account
     * not linked to anyone else (409 google_already_linked) and the user
     * without another one (409 google_identity_exists). Linking the same
     * account again is a no-op.
     *
     * @throws ApiException
     */
    public function link(User $user, VerifiedGoogleIdentity $google, string $via, ?User $actor): UserGoogleIdentity
    {
        $existing = $this->assertCanLink($user, $google);
        if ($existing !== null) {
            return $existing;
        }

        try {
            $identity = DB::transaction(fn () => UserGoogleIdentity::create([
                'user_id' => $user->id,
                'google_sub' => $google->sub,
                'email' => $google->email,
                'name' => $google->name,
                'picture_url' => $google->picture,
                'linked_via' => $via,
                'linked_by' => $actor?->id,
                'notice_version' => GoogleSignInConfig::NOTICE_VERSION,
                'linked_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            // Another request linked the same Google account or the same user meanwhile.
            self::log('link', 'conflict', $user->id, $via);

            throw UserGoogleIdentity::query()->where('google_sub', $google->sub)->exists()
                ? GoogleSignInErrors::alreadyLinked()
                : GoogleSignInErrors::identityExists();
        }
        self::log('link', 'ok', $user->id, $via, $actor?->id);

        return $identity;
    }

    /**
     * The checks of link() without writing anything; returns the existing
     * row when $google is already linked to $user.
     *
     * @throws ApiException
     */
    public function assertCanLink(User $user, VerifiedGoogleIdentity $google): ?UserGoogleIdentity
    {
        $bySub = UserGoogleIdentity::query()->where('google_sub', $google->sub)->first();
        if ($bySub !== null) {
            if ((int) $bySub->user_id === (int) $user->id) {
                return $bySub;
            }
            self::log('link', 'google_already_linked', $user->id);

            throw GoogleSignInErrors::alreadyLinked();
        }
        if (UserGoogleIdentity::query()->where('user_id', $user->id)->exists()) {
            self::log('link', 'google_identity_exists', $user->id);

            throw GoogleSignInErrors::identityExists();
        }

        return null;
    }

    /** Removes the user's Google link (§24.9.5); false when there was none. */
    public function unlink(User $user, ?User $actor, string $how): bool
    {
        $deleted = UserGoogleIdentity::query()->where('user_id', $user->id)->delete() > 0;
        if ($deleted) {
            self::log('unlink', 'ok', $user->id, $how, $actor?->id);
        }

        return $deleted;
    }

    /**
     * Filament "ลบการเชื่อม Google ของนักเรียนทั้งหมด" (§24.9.2): removes
     * the Google link of every student of $school. Returns how many.
     */
    public function unlinkSchoolStudents(School $school, User $actor): int
    {
        $count = UserGoogleIdentity::query()
            ->whereIn('user_id', User::query()->select('id')->where('school_id', $school->id)->where('role', User::ROLE_STUDENT))
            ->delete();
        Log::info('google_signin', ['event' => 'unlink_school_students', 'result' => 'ok', 'school_id' => $school->id, 'count' => $count, 'actor_id' => $actor->id]);

        return $count;
    }

    /** Token of the user's role, exactly like the password, PIN and QR logins (§7.4). */
    public function issueToken(User $user, ?string $deviceName): NewAccessToken
    {
        if ($user->isStudent()) {
            return $this->students->issueToken($user, $deviceName);
        }
        $ttlDays = $user->isAdmin()
            ? (int) config('eduvision.token_ttl_days.admin', TeacherAuthController::ADMIN_TOKEN_TTL_DAYS)
            : (int) config('eduvision.token_ttl_days.teacher', TeacherAuthController::TOKEN_TTL_DAYS);

        return $user->createToken($deviceName ?: 'app', [$user->role], now()->addDays($ttlDays));
    }

    /** One audit line: the event, its result, the user ids and the link path; never an e-mail or token. */
    public static function log(string $event, string $result, ?int $userId = null, ?string $via = null, ?int $actorId = null): void
    {
        Log::info('google_signin', array_filter([
            'event' => $event,
            'result' => $result,
            'user_id' => $userId,
            'via' => $via,
            'actor_id' => $actorId,
        ], fn ($v) => $v !== null));
    }

    /**
     * Step 2 of §24.9.3: the user must be active (not pending, disabled or
     * merged); then the stored profile follows Google and the role's token
     * is issued.
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function complete(UserGoogleIdentity $identity, VerifiedGoogleIdentity $google, ?string $deviceName): array
    {
        $user = $identity->user;
        if (! $user->isActive() || $user->isMerged()) {
            self::log('login', 'account_not_active', $user->id);

            throw GoogleSignInErrors::accountNotActive($user->isMerged() ? User::STATUS_DISABLED : $user->status);
        }

        $identity->forceFill([
            'email' => $google->email,
            'name' => $google->name,
            'picture_url' => $google->picture,
            'last_login_at' => now(),
        ])->save();
        self::log('login', 'ok', $user->id);

        return ['token' => $this->issueToken($user, $deviceName), 'user' => $user];
    }

    /**
     * §24.9.3 step 3: a teacher whose e-mail is the verified one is linked
     * automatically; an admin never is (#61). An unknown account becomes an
     * active teacher at once (§29.13.2, googleSignUp()); an e-mail another
     * user holds gets a link ticket with the registration prefill instead.
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function firstStaffSignIn(VerifiedGoogleIdentity $google, ?string $deviceName): array
    {
        $staff = User::query()
            ->with('school')
            ->whereRaw('LOWER(email) = ?', [$google->email])
            ->whereIn('role', [User::ROLE_TEACHER, User::ROLE_ADMIN])
            ->orderBy('id')
            ->first();

        if ($staff !== null && $staff->isAdmin()) {
            self::log('login', 'google_not_linked', $staff->id, 'admin_email');

            throw GoogleSignInErrors::notLinked('เข้าสู่ระบบด้วยรหัสผ่านก่อน แล้วกดเชื่อมบัญชี Google ในหน้าผู้ดูแลระบบ');
        }

        if ($staff !== null) {
            if (UserGoogleIdentity::query()->where('user_id', $staff->id)->exists()) {
                self::log('login', 'google_not_linked', $staff->id, 'other_identity');

                throw GoogleSignInErrors::notLinked('บัญชีครูที่ใช้อีเมลนี้เชื่อมกับบัญชี Google อื่นอยู่แล้ว กรุณาเข้าสู่ระบบด้วยบัญชี Google นั้นหรือรหัสผ่าน');
            }
            $identity = $this->link($staff, $google, UserGoogleIdentity::VIA_TEACHER_EMAIL, null);

            return $this->complete($identity->setRelation('user', $staff), $google, $deviceName);
        }

        return $this->googleSignUp($google, $deviceName);
    }

    /**
     * §29.13.2: the Google account is unknown and no teacher or admin has
     * its e-mail, so it becomes an active teacher at once (name and e-mail
     * from Google, no password, approved_by null) with a workspace of its
     * own, linked as `google_signup`. When another user already has the
     * e-mail (users.email is unique) the pending registration of #70.
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function googleSignUp(VerifiedGoogleIdentity $google, ?string $deviceName): array
    {
        if (User::query()->whereRaw('LOWER(email) = ?', [$google->email])->exists()) {
            // A student (or a merged account) has the e-mail: users.email is unique.
            self::log('signup', 'email_taken');

            throw $this->registration($google);
        }

        try {
            $identity = DB::transaction(function () use ($google) {
                $name = self::signUpName($google);
                $school = Workspaces::create($name, null);
                $user = User::create([
                    'school_id' => $school->id,
                    'role' => User::ROLE_TEACHER,
                    'name' => $name,
                    'email' => $google->email,
                    'password' => null,
                    'status' => User::STATUS_ACTIVE,
                ])->setRelation('school', $school);

                return $this->link($user, $google, UserGoogleIdentity::VIA_GOOGLE_SIGNUP, null)->setRelation('user', $user);
            });
        } catch (UniqueConstraintViolationException) {
            // Another request created the account meanwhile: sign in with it if it is linked now.
            $identity = UserGoogleIdentity::query()->with('user.school')->where('google_sub', $google->sub)->first();
            if ($identity !== null && $identity->user !== null) {
                return $this->complete($identity, $google, $deviceName);
            }
            self::log('signup', 'conflict');

            throw $this->registration($google);
        }
        self::log('signup', 'ok', (int) $identity->user_id, UserGoogleIdentity::VIA_GOOGLE_SIGNUP);

        return $this->complete($identity, $google, $deviceName);
    }

    /** 404 google_not_linked for an unknown teacher whose e-mail is taken: a link ticket and the registration prefill. */
    private function registration(VerifiedGoogleIdentity $google): ApiException
    {
        return GoogleSignInErrors::notLinked(
            'ยังไม่มีบัญชี Krucheck ที่เชื่อมกับบัญชี Google นี้ สมัครใช้งานครู หรือเข้าสู่ระบบด้วยรหัสผ่านแล้วเชื่อมบัญชี Google ในหน้าตั้งค่า',
            [
                'link_ticket' => $this->tickets->issueLink($google),
                'registration' => ['name' => $google->name, 'email' => $google->email],
            ],
        );
    }

    /** The name Google gives, else the part of the e-mail before `@`. */
    private static function signUpName(VerifiedGoogleIdentity $google): string
    {
        $name = trim((string) $google->name);
        if ($name === '') {
            $at = strrpos($google->email, '@');
            $name = $at === false ? $google->email : substr($google->email, 0, $at);
        }

        return mb_substr($name, 0, 255);
    }

    /**
     * §24.9.3 step 4: a student is linked from the Classroom roster only when
     * a roster row has both the token's `sub` (Classroom userId) and its
     * verified e-mail, every row with either belongs to that one student,
     * and the student is active, unmerged and without another Google
     * account. Anything else gets a link ticket for the PIN/QR confirmation.
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function firstStudentSignIn(VerifiedGoogleIdentity $google, ?string $deviceName): array
    {
        $student = $this->rosterStudent($google);
        if ($student !== null) {
            $identity = $this->link($student, $google, UserGoogleIdentity::VIA_CLASSROOM_ROSTER, null);

            return $this->complete($identity->setRelation('user', $student), $google, $deviceName);
        }

        self::log('login', 'google_not_linked', null, 'unknown_student');

        throw GoogleSignInErrors::notLinked(
            'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชีนักเรียน ถ้าห้องของคุณใช้ Google Classroom และเพิ่งเข้าคอร์ส '
            .'รอประมาณ '.self::rosterWaitMinutes().' นาทีแล้วกดเข้าสู่ระบบด้วย Google อีกครั้ง ระบบจะเชื่อมให้เอง '
            .'ถ้าครูให้รหัสห้องมา กรอกรหัสห้องเพื่อสร้างบัญชี หรือยืนยันตัวตนครั้งแรกด้วยชื่อผู้ใช้และรหัสผ่านที่ครูให้',
            ['link_ticket' => $this->tickets->issueLink($google)],
        );
    }

    /** The longest wait before the cron roster sync (§19.3) matches a new member of a linked course. */
    public static function rosterWaitMinutes(): int
    {
        return max(1, (int) config('eduvision.classroom_sync.roster_minutes')) + (int) ceil(ClassroomSyncJob::INTERVAL_SECONDS / 60);
    }

    private function rosterStudent(VerifiedGoogleIdentity $google): ?User
    {
        $bySub = DB::table('classroom_students')->where('google_user_id', $google->sub)->get(['student_id', 'google_email']);
        if ($bySub->isEmpty()) {
            return null;
        }
        $byEmail = DB::table('classroom_students')->whereRaw('LOWER(google_email) = ?', [$google->email])->pluck('student_id');

        $ids = $bySub->pluck('student_id')->merge($byEmail)->map(fn ($id) => (int) $id)->unique()->values();
        $both = $bySub->contains(fn ($row) => mb_strtolower(trim((string) $row->google_email)) === $google->email);
        if ($ids->count() !== 1 || ! $both) {
            return null;
        }

        $student = User::query()->with('school')->find($ids->first());
        if ($student === null || ! $student->isStudent() || ! $student->isActive() || $student->isMerged()) {
            return null;
        }

        return UserGoogleIdentity::query()->where('user_id', $student->id)->exists() ? null : $student;
    }
}
