<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\Impersonations;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/auth/impersonate/ticket {ticket, device_name?} -> {token, user}
 * (DESIGN §29.14): the web app redeems the one-time ticket the panel's
 * "เข้าใช้แทน" put in its URL and signs in as that user. 422
 * impersonation_ticket_invalid for anything but a fresh, unused ticket.
 */
class ImpersonationController extends Controller
{
    public function ticket(Request $request, Impersonations $impersonations): JsonResponse
    {
        $data = $request->validate([
            'ticket' => ['required', 'string', 'max:64'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ], ['ticket.required' => 'ไม่ได้รับรหัสเข้าใช้แทน']);

        $pair = $impersonations->redeem($data['ticket']);
        if ($pair === null) {
            throw new ApiException(
                'ลิงก์เข้าใช้แทนใช้ไม่ได้แล้ว (หมดอายุภายใน 1 นาทีและใช้ได้ครั้งเดียว) กลับไปที่หน้าผู้ดูแลระบบแล้วกด "เข้าใช้แทน" อีกครั้ง',
                'impersonation_ticket_invalid',
                422,
            );
        }
        $token = $impersonations->start($pair['admin'], $pair['user'], $data['device_name'] ?? null);

        return response()->json([
            'token' => $token->plainTextToken,
            'user' => new UserResource($pair['user']->withAccessToken($token->accessToken)->loadMissing('school')),
        ]);
    }
}
