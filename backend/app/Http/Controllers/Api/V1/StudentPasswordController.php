<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\CredentialIssuer;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * PUT /api/v1/student/password {password, current_password?} -> {user}
 * (DESIGN §29.10). The student sets their own password: at least 6
 * characters and not the initial one. current_password is required unless
 * the password is still the initial one (the student has just signed in
 * with it). Every other session of the student ends.
 */
class StudentPasswordController extends Controller
{
    public function __invoke(Request $request, CredentialIssuer $issuer): JsonResponse
    {
        $student = $request->user();
        $credential = $student->credential;
        if ($credential === null) {
            throw new ApiException('บัญชีนี้ยังไม่มีรหัสผ่าน ให้ครูรีเซ็ตรหัสผ่านให้ก่อน', 'no_password', 409);
        }
        $data = $request->validate([
            'password' => ['required', 'string', 'min:'.CredentialIssuer::MIN_PASSWORD_LENGTH, 'max:72', 'not_in:'.CredentialIssuer::INITIAL_PASSWORD],
            'current_password' => [$credential->must_change_password ? 'nullable' : 'required', 'string', 'max:72'],
        ], [
            'password.required' => 'กรุณากรอกรหัสผ่านใหม่',
            'password.min' => 'รหัสผ่านต้องยาวอย่างน้อย '.CredentialIssuer::MIN_PASSWORD_LENGTH.' ตัว',
            'password.max' => 'รหัสผ่านยาวเกินไป',
            'password.not_in' => 'ห้ามใช้ '.CredentialIssuer::INITIAL_PASSWORD.' เป็นรหัสผ่าน',
            'current_password.required' => 'กรุณากรอกรหัสผ่านปัจจุบัน',
        ]);
        if (! $credential->must_change_password && ! Hash::check((string) $data['current_password'], $credential->pin_hash)) {
            $message = 'รหัสผ่านปัจจุบันไม่ถูกต้อง';

            throw new ApiException($message, 'validation_failed', 422, ['current_password' => [$message]]);
        }

        $issuer->setPassword($student, $data['password'], $student->currentAccessToken()?->id);

        return response()->json(['user' => new UserResource($student->refresh()->loadMissing('school'))]);
    }
}
