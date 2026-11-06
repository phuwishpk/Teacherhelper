<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Auth\AdminHandoffs;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/auth/admin-handoff -> {data: {url, expires_at}} (DESIGN §7.4, §9.1)
 *
 * Admin only (`role:admin`: the admin role plus a token with the `admin`
 * ability). The url is a one-time link to the Filament panel that works for
 * 60 seconds; the app opens it in the browser.
 */
class AdminHandoffController extends Controller
{
    public function store(Request $request, AdminHandoffs $handoffs): JsonResponse
    {
        ['token' => $token, 'expires_at' => $expiresAt] = $handoffs->issue($request->user());

        return response()->json(['data' => [
            'url' => route('admin.handoff', ['token' => $token]),
            'expires_at' => $expiresAt->utc()->toIso8601ZuluString(),
        ]]);
    }
}
