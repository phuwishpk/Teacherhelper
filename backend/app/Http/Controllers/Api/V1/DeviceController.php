<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StoreDeviceRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * POST /api/v1/devices {fcm_token} -> 201 {id, fcm_token, last_seen_at} (DESIGN §9.1).
 *
 * fcm_token is unique per install: registering it again refreshes last_seen_at,
 * and a token that another user registered on the same phone moves to the
 * current user (the phone changed accounts).
 */
class DeviceController extends Controller
{
    public function store(StoreDeviceRequest $request): JsonResponse
    {
        Gate::authorize('create', DeviceToken::class);

        $device = DeviceToken::query()->updateOrCreate(
            ['fcm_token' => $request->validated('fcm_token')],
            ['user_id' => $request->user()->id, 'last_seen_at' => now()],
        );

        return response()->json([
            'id' => $device->id,
            'fcm_token' => $device->fcm_token,
            'last_seen_at' => $device->last_seen_at->toIso8601String(),
        ], $device->wasRecentlyCreated ? 201 : 200);
    }
}
