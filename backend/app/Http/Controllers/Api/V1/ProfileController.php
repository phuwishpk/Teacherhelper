<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

/**
 * The teacher's own profile (DESIGN §29.1): their name, the school name
 * they want shown (free text, optional) and their password.
 */
class ProfileController extends Controller
{
    /** PATCH /api/v1/me {name?, school_name?} -> {data: user}; an empty school_name clears it. */
    public function update(Request $request): UserResource
    {
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'school_name' => ['sometimes', 'nullable', 'string', 'max:150'],
        ], [
            'name.required' => 'กรุณากรอกชื่อ',
            'name.max' => 'ชื่อยาวเกินไป',
            'school_name.max' => 'ชื่อโรงเรียนยาวไม่เกิน 150 ตัวอักษร',
        ]);
        $user = $request->user();
        if (array_key_exists('name', $data)) {
            $name = trim($data['name']);
            if ($name === '') {
                throw new ApiException('กรุณากรอกชื่อ', 'validation_failed', 422, ['name' => ['กรุณากรอกชื่อ']]);
            }
            $user->name = $name;
        }
        if (array_key_exists('school_name', $data)) {
            $schoolName = trim((string) $data['school_name']);
            $user->school_name = $schoolName === '' ? null : $schoolName;
        }
        $user->save();

        return new UserResource($user->loadMissing('school'));
    }

    /**
     * PUT /api/v1/me/password {password, current_password?} -> 204.
     * current_password is required when the account has a password (a
     * teacher who signed up with Google has none yet). Every other session
     * of the teacher ends.
     */
    public function password(Request $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'password' => ['required', 'string', 'max:72', Password::min(8)],
            'current_password' => [$user->password === null ? 'nullable' : 'required', 'string', 'max:72'],
        ], [
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.min' => 'รหัสผ่านต้องยาวอย่างน้อย 8 ตัว',
            'password.max' => 'รหัสผ่านยาวเกินไป',
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
        ]);
        if ($user->password !== null && ! Hash::check((string) $data['current_password'], $user->password)) {
            $message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';

            throw new ApiException($message, 'validation_failed', 422, ['current_password' => [$message]]);
        }

        $user->password = $data['password'];
        $user->save();
        $user->tokens()->whereKeyNot($user->currentAccessToken()?->id)->delete();

        return response()->json(null, 204);
    }
}
