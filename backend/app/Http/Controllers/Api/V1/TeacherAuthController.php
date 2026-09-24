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
 * Teacher auth (DESIGN §9.1, §7.4): register, login, logout.
 */
class TeacherAuthController extends Controller
{
    /** Sanctum token lifetime for teachers (DESIGN §7.4). */
    public const TOKEN_TTL_DAYS = 30;

    /**
     * POST /api/v1/auth/teacher/register -> 201 {user}
     *
     * M0 stubs (KICKOFF Day 2 step 5):
     *  (a) school_code is checked only against schools.teacher_join_code of the seeded school(s);
     *  (b) the account becomes `active` immediately, no admin approval yet.
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
            'status' => User::STATUS_ACTIVE, // TODO(phase-2): set status=pending and approve via Filament
        ]);

        return response()->json([
            'user' => new UserResource($user->setRelation('school', $school)),
        ], 201);
    }

    /**
     * POST /api/v1/auth/teacher/login -> 200 {token, user}
     */
    public function login(TeacherLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        $user = User::query()
            ->where('email', $data['email'])
            ->where('role', User::ROLE_TEACHER)
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

        $token = $user->createToken(
            $data['device_name'] ?? 'app',
            ['teacher'],
            now()->addDays(self::TOKEN_TTL_DAYS),
        );

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
