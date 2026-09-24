<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Students\StudentAuthenticator;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StudentPinLoginRequest;
use App\Http\Requests\Api\V1\StudentQrLoginRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Laravel\Sanctum\NewAccessToken;

/**
 * Student auth (DESIGN §9.1): QR card (main) and class code + number + PIN (fallback).
 */
class StudentAuthController extends Controller
{
    public function __construct(private readonly StudentAuthenticator $authenticator) {}

    /** POST /api/v1/auth/student/qr -> 200 {token, user} | 422 qr_invalid */
    public function qr(StudentQrLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->tokenResponse(
            $this->authenticator->loginWithQr($data['qr_token'], $data['device_name'] ?? null),
        );
    }

    /** POST /api/v1/auth/student/pin -> 200 {token, user} | 422 invalid_credentials | 423 pin_locked */
    public function pin(StudentPinLoginRequest $request): JsonResponse
    {
        $data = $request->validated();

        return $this->tokenResponse(
            $this->authenticator->loginWithPin(
                $data['class_code'],
                (int) $data['student_number'],
                $data['pin'],
                $data['device_name'] ?? null,
            ),
        );
    }

    private function tokenResponse(NewAccessToken $token): JsonResponse
    {
        $student = $token->accessToken->tokenable;

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($student->loadMissing('school')),
        ]);
    }
}
