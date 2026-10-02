<?php

namespace App\Domain\Auth\Google;

use App\Domain\Students\StudentAuthenticator;
use App\Exceptions\ApiException;
use App\Http\Controllers\Api\V1\TeacherAuthController;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\NewAccessToken;

/**
 * Google sign-in for every role (DESIGN §24.9): signing in with a linked
 * Google account, the automatic links (a teacher's matching e-mail, a
 * student's Classroom roster entry), the explicit links and unlinking.
 * An unknown staff account becomes an active teacher at once when the
 * school allows it (`teacher_google_auto_approve`, #71); otherwise, and
 * always for a student, it gets 404 google_not_linked with a link ticket.
 *
 * Every path checks the allowed domains of the user's school and, for a
 * student, the school's switch. Each outcome is logged as `google_signin`
 * with the event, the result and the user id only (no e-mail, no token).
 */
final class GoogleSignIn
{
    public const INTENT_STAFF = 'staff';

    public const INTENT_STUDENT = 'student';

    public function __construct(
        private readonly GoogleSignInTickets $tickets,
        private readonly StudentAuthenticator $students,
    ) {}

    /**
     * POST /auth/google (and the browser flow's ticket): a token for the user
     * linked to $google, or the first sign-in of §24.9.3 steps 3-4.
     * $schoolId is the school an unknown teacher picked (staff only).
     *
     * @return array{token: NewAccessToken, user: User}
     *
     * @throws ApiException 404 google_not_linked, 403 account_not_active / google_domain_not_allowed / student_google_disabled
     */
    public function signIn(VerifiedGoogleIdentity $google, string $intent, ?string $deviceName = null, ?int $schoolId = null): array
    {
        $identity = UserGoogleIdentity::query()->with('user.school')->where('google_sub', $google->sub)->first();
        if ($identity !== null && $identity->user !== null) {
            return $this->complete($identity, $google, $deviceName);
        }

        return $intent === self::INTENT_STUDENT
            ? $this->firstStudentSignIn($google, $deviceName)
            : $this->firstStaffSignIn($google, $deviceName, $schoolId);
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
     * Links $google to $user after every check of §24.9.5: the school's
     * domains and student switch, the Google account not linked to anyone
     * else (409 google_already_linked) and the user without another one
     * (409 google_identity_exists). Linking the same account again is a no-op.
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
        self::assertAllowed($user, $google);

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

    /**
     * The school's rules for $user and $google (§24.9.2): the student switch
     * (403 student_google_disabled) and the allowed domains (403
     * google_domain_not_allowed). A system admin (no school) is not limited.
     *
     * @throws ApiException
     */
    public static function assertAllowed(User $user, VerifiedGoogleIdentity $google): void
    {
        $school = $user->school;
        if ($user->isStudent() && ! ($school?->student_google_signin ?? false)) {
            self::log('check', 'student_google_disabled', $user->id);

            throw GoogleSignInErrors::studentDisabled();
        }
        if ($school !== null && ! $school->allowsGoogleDomain($google->domain())) {
            self::log('check', 'google_domain_not_allowed', $user->id);

            throw GoogleSignInErrors::domainNotAllowed();
        }
    }

    /** Whether $user may link a Google account at all (GET /me/google-identity `can_link`). */
    public static function canLink(User $user): bool
    {
        return ! $user->isStudent() || (bool) ($user->school?->student_google_signin ?? false);
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
     * merged) and pass the school's rules; then the stored profile follows
     * Google and the role's token is issued.
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
        self::assertAllowed($user, $google);

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
     * active teacher of the school when that school allows it (#71, see
     * googleSignUp()); anybody else gets a link ticket with the
     * registration prefill (the pending registration of #70).
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function firstStaffSignIn(VerifiedGoogleIdentity $google, ?string $deviceName, ?int $schoolId): array
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
            self::assertAllowed($staff, $google);
            if (UserGoogleIdentity::query()->where('user_id', $staff->id)->exists()) {
                self::log('login', 'google_not_linked', $staff->id, 'other_identity');

                throw GoogleSignInErrors::notLinked('บัญชีครูที่ใช้อีเมลนี้เชื่อมกับบัญชี Google อื่นอยู่แล้ว กรุณาเข้าสู่ระบบด้วยบัญชี Google นั้นหรือรหัสผ่าน');
            }
            $identity = $this->link($staff, $google, UserGoogleIdentity::VIA_TEACHER_EMAIL, null);

            return $this->complete($identity->setRelation('user', $staff), $google, $deviceName);
        }

        return $this->googleSignUp($google, $deviceName, $schoolId);
    }

    /**
     * §24.9.3 step 3b (#71): the Google account is unknown and no teacher or
     * admin has its e-mail. The school is $schoolId, else the only school;
     * with several schools and no choice the app asks (404 with
     * `needs_school`). A school with `teacher_google_auto_approve` creates an
     * active teacher (name and e-mail from Google, no password, approved_by
     * null), links the account (`google_signup`) and signs in after its
     * domain check (403 google_domain_not_allowed). Otherwise, or when
     * another user already has the e-mail, the pending registration of #70.
     *
     * @return array{token: NewAccessToken, user: User}
     */
    private function googleSignUp(VerifiedGoogleIdentity $google, ?string $deviceName, ?int $schoolId): array
    {
        $school = $schoolId !== null ? School::query()->find($schoolId) : null;
        if ($school === null) {
            $only = School::query()->limit(2)->get();
            if ($only->count() > 1 && $this->anySchoolAutoApproves($google)) {
                self::log('login', 'google_not_linked', null, 'needs_school');

                throw $this->registration($google, ['needs_school' => true]);
            }
            $school = $only->count() === 1 ? $only->first() : null;
        }

        if ($school === null || ! $school->teacher_google_auto_approve) {
            self::log('login', 'google_not_linked', null, 'unknown_staff');

            throw $this->registration($google);
        }
        if (! $school->allowsGoogleDomain($google->domain())) {
            self::log('signup', 'google_domain_not_allowed');

            throw GoogleSignInErrors::domainNotAllowed();
        }
        if (User::query()->whereRaw('LOWER(email) = ?', [$google->email])->exists()) {
            // A student (or a merged account) has the e-mail: users.email is unique.
            self::log('signup', 'email_taken');

            throw $this->registration($google);
        }

        try {
            $identity = DB::transaction(function () use ($google, $school) {
                $user = User::create([
                    'school_id' => $school->id,
                    'role' => User::ROLE_TEACHER,
                    'name' => self::signUpName($google),
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

    /** Whether choosing a school could create the account at all (else the plain registration). */
    private function anySchoolAutoApproves(VerifiedGoogleIdentity $google): bool
    {
        return School::query()
            ->where('teacher_google_auto_approve', true)
            ->get(['id', 'google_signin_domains'])
            ->contains(fn (School $school) => $school->allowsGoogleDomain($google->domain()));
    }

    /**
     * 404 google_not_linked for an unknown teacher: a link ticket and the
     * registration prefill, plus `needs_school` when the app must ask.
     *
     * @param  array<string, mixed>  $extra
     */
    private function registration(VerifiedGoogleIdentity $google, array $extra = []): ApiException
    {
        return GoogleSignInErrors::notLinked(
            ($extra['needs_school'] ?? false)
                ? 'เลือกโรงเรียนของคุณ แล้วเข้าสู่ระบบด้วย Google อีกครั้ง'
                : 'ยังไม่มีบัญชี EduVision ที่เชื่อมกับบัญชี Google นี้ สมัครใช้งานครู หรือเข้าสู่ระบบด้วยรหัสผ่านแล้วเชื่อมบัญชี Google ในหน้าตั้งค่า',
            [
                'link_ticket' => $this->tickets->issueLink($google),
                'registration' => ['name' => $google->name, 'email' => $google->email],
                ...$extra,
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
            self::assertAllowed($student, $google);
            $identity = $this->link($student, $google, UserGoogleIdentity::VIA_CLASSROOM_ROSTER, null);

            return $this->complete($identity->setRelation('user', $student), $google, $deviceName);
        }

        self::log('login', 'google_not_linked', null, 'unknown_student');

        throw GoogleSignInErrors::notLinked(
            'บัญชี Google นี้ยังไม่ได้เชื่อมกับบัญชีนักเรียน ยืนยันตัวตนครั้งแรกด้วยรหัสห้อง เลขที่ และ PIN หรือสแกนบัตร QR',
            ['link_ticket' => $this->tickets->issueLink($google)],
        );
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
