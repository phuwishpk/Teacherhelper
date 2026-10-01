<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\TeacherLoginRequest;
use App\Http\Requests\Api\V1\TeacherRegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\School;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
     * school_code must match schools.teacher_join_code. The account is created
     * `pending` (DESIGN §9.1) and can log in only after an admin approves it in
     * Filament (UserResource "approve"), which sets status=active + approved_by.
     */
    public function register(TeacherRegisterRequest $request): JsonResponse
    {
        $data = $request->validated();

        $school = School::query()->where('teacher_join_code', $data['school_code'])->first();
        if ($school === null) {
            throw new ApiException(
                'รหัสโรงเรียนไม่ถูกต้อง',
                'school_code_invalid',
                422,
                ['school_code' => ['รหัสโรงเรียนไม่ถูกต้อง']],
            );
        }

        $user = User::create([
            'school_id' => $school->id,
            'role' => User::ROLE_TEACHER,
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'status' => User::STATUS_PENDING,
        ]);

        return response()->json([
            'user' => new UserResource($user->setRelation('school', $school)),
        ], 201);
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
