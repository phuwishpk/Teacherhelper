<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Google\GoogleSignIn;
use App\Domain\Auth\Google\GoogleSignInConfig;
use App\Domain\Auth\Google\GoogleSignInErrors;
use App\Domain\Auth\Google\GoogleSignInTickets;
use App\Domain\Auth\Google\VerifiedGoogleIdentity;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TeacherLoginRequest;
use App\Http\Requests\Api\V1\TeacherRegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\School;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Teacher auth (DESIGN §9.1, §7.4): register, login (teachers and admins), logout.
 */
class TeacherAuthController extends Controller
{
    /** Sanctum token lifetime for teachers (DESIGN §7.4). */
    public const TOKEN_TTL_DAYS = 30;

    /**
     * POST /api/v1/auth/teacher/register -> 201 {user}
     *
     * The school comes from resolveSchool() (school_id, the legacy school_code,
     * or the only school). The account is created `pending` (DESIGN §9.1) and
     * can log in only after an admin approves it in Filament (UserResource
     * "approve"), which sets status=active + approved_by. Since 2 Oct 2569 that
     * approval is the only gate: no school code is needed to sign up.
     *
     * google_link_ticket (DESIGN §24.9.5): the Google account of a 404
     * google_not_linked is linked as the account is created (`registration`),
     * after the school's domain check; it signs in once an admin approves.
     * 422 link_ticket_invalid, 403 google_domain_not_allowed, 409 google_already_linked.
     */
    public function register(TeacherRegisterRequest $request, GoogleSignInTickets $tickets, GoogleSignIn $signIn): JsonResponse
    {
        $data = $request->validated();

        $school = self::resolveSchool($data);

        $ticket = $data['google_link_ticket'] ?? null;
        $google = $ticket === null || $ticket === '' ? null : self::registrationIdentity($tickets, $ticket, $school);

        $user = DB::transaction(function () use ($data, $school, $google, $ticket, $tickets, $signIn) {
            $user = User::create([
                'school_id' => $school->id,
                'role' => User::ROLE_TEACHER,
                'name' => $data['name'],
                'email' => $data['email'],
                'password' => $data['password'],
                'status' => User::STATUS_PENDING,
            ]);
            if ($google !== null) {
                if ($tickets->consumeLink($ticket) === null) {
                    throw GoogleSignInErrors::linkTicketInvalid('google_link_ticket');
                }
                $signIn->link($user->setRelation('school', $school), $google, UserGoogleIdentity::VIA_REGISTRATION, $user);
            }

            return $user;
        });

        return response()->json([
            'user' => new UserResource($user->setRelation('school', $school)),
        ], 201);
    }

    /**
     * GET /api/v1/auth/schools -> 200 {data: [{id, name}]}
     *
     * Public: the app's sign-up form shows a "โรงเรียน" dropdown when there is
     * more than one school. Names only; the join codes never leave the panel.
     */
    public function schools(): JsonResponse
    {
        $schools = School::query()->orderBy('name')->orderBy('id')->get(['id', 'name']);

        return response()->json([
            'data' => $schools->map(fn (School $school) => ['id' => $school->id, 'name' => $school->name])->values(),
        ]);
    }

    /**
     * The school a new teacher joins: school_id when given (validated to
     * exist), else school_code from an older app build (422
     * school_code_invalid when it matches nothing), else the only school.
     * With no or several schools and neither field: 422 school_required.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ApiException
     */
    private static function resolveSchool(array $data): School
    {
        if (isset($data['school_id'])) {
            return School::query()->findOrFail((int) $data['school_id']);
        }

        if (isset($data['school_code']) && $data['school_code'] !== '') {
            $school = School::query()->where('teacher_join_code', $data['school_code'])->first();
            if ($school === null) {
                throw new ApiException(
                    'รหัสโรงเรียนไม่ถูกต้อง',
                    'school_code_invalid',
                    422,
                    ['school_code' => ['รหัสโรงเรียนไม่ถูกต้อง']],
                );
            }

            return $school;
        }

        $only = School::query()->limit(2)->get();
        if ($only->count() === 1) {
            return $only->first();
        }

        throw new ApiException(
            'กรุณาเลือกโรงเรียน',
            'school_required',
            422,
            ['school_id' => ['กรุณาเลือกโรงเรียน']],
        );
    }

    /**
     * The Google account behind a registration's link ticket, checked
     * against the school's domains and existing links (nothing spent yet).
     *
     * @throws ApiException
     */
    private static function registrationIdentity(GoogleSignInTickets $tickets, string $ticket, School $school): VerifiedGoogleIdentity
    {
        if (! GoogleSignInConfig::enabled()) {
            throw GoogleSignInErrors::notConfigured();
        }
        $google = $tickets->peekLink($ticket);
        if ($google === null) {
            throw GoogleSignInErrors::linkTicketInvalid('google_link_ticket');
        }
        if (! $school->allowsGoogleDomain($google->domain())) {
            GoogleSignIn::log('register', 'google_domain_not_allowed');

            throw GoogleSignInErrors::domainNotAllowed();
        }
        if (UserGoogleIdentity::query()->where('google_sub', $google->sub)->exists()) {
            GoogleSignIn::log('register', 'google_already_linked');

            throw GoogleSignInErrors::alreadyLinked();
        }

        return $google;
    }

    /** Sanctum token lifetime for admins: their token only opens the panel handoff. */
    public const ADMIN_TOKEN_TTL_DAYS = 1;

    /**
     * POST /api/v1/auth/teacher/login -> 200 {token, user}
     *
     * The unified login page of the app (DESIGN §7.4) sends teachers and
     * admins here. The token carries the ability of the account's role:
     * `teacher` opens the teacher API as before; `admin` opens nothing but
     * /me, logout and POST /auth/admin-handoff (`role:admin`), because the
     * admin works in the Filament panel. `user.role` tells the app which.
     */
    public function login(TeacherLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()
            ->where('email', $data['email'])
            ->whereIn('role', [User::ROLE_TEACHER, User::ROLE_ADMIN])
            ->first();

        if ($user === null || $user->password === null || ! Hash::check($data['password'], $user->password)) {
            throw new ApiException(
                'อีเมลหรือรหัสผ่านไม่ถูกต้อง',
                'invalid_credentials',
                422,
                ['email' => ['อีเมลหรือรหัสผ่านไม่ถูกต้อง']],
            );
        }

        if (! $user->isActive()) {
            throw new ApiException(
                $user->status === User::STATUS_PENDING
                    ? 'บัญชีของคุณกำลังรอการอนุมัติจากผู้ดูแลระบบ'
                    : 'บัญชีของคุณถูกระงับการใช้งาน',
                'account_not_active',
                403,
            );
        }

        $ttlDays = $user->isAdmin()
            ? (int) config('eduvision.token_ttl_days.admin', self::ADMIN_TOKEN_TTL_DAYS)
            : (int) config('eduvision.token_ttl_days.teacher', self::TOKEN_TTL_DAYS);
        $token = $user->createToken($data['device_name'] ?? 'app', [$user->role], now()->addDays($ttlDays));

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($user->loadMissing('school')),
        ]);
    }

    /**
     * POST /api/v1/auth/logout -> 204. Revokes only the token used for this request.
     */
    public function logout(Request $request): Response
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }
}
