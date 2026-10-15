<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\TeacherAiKeys;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\UpdateAiKeyRequest;
use App\Models\TeacherApiKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * The signed-in teacher's own Gemini key (DESIGN §9.1, §10.1). Every answer
 * is {data: {provider, configured, key_last4, last_verified_at,
 * server_key_available}}; the key itself never comes back.
 */
class AiKeyController extends Controller
{
    public function __construct(private readonly TeacherAiKeys $keys) {}

    /** GET /api/v1/me/ai-key */
    public function show(Request $request): JsonResponse
    {
        Gate::authorize('manage', TeacherApiKey::class);

        return response()->json(['data' => $this->keys->status($request->user())]);
    }

    /**
     * PUT /api/v1/me/ai-key {gemini_api_key}: test-calls Gemini (models.list)
     * first. Refused key -> 422 ai_key_invalid; Gemini unreachable -> 503
     * ai_unavailable. Nothing is stored unless the call succeeded.
     */
    public function update(UpdateAiKeyRequest $request): JsonResponse
    {
        Gate::authorize('manage', TeacherApiKey::class);

        try {
            $this->keys->save($request->user(), (string) $request->validated('gemini_api_key'));
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::KEY_INVALID) {
                $message = 'Gemini ไม่ยอมรับ key นี้ ตรวจว่าคัดลอกมาครบ และเปิดใช้ Generative Language API แล้ว';
                throw new ApiException($message, 'ai_key_invalid', 422, ['gemini_api_key' => [$message]]);
            }
            throw new ApiException('ตรวจสอบ key กับ Gemini ไม่ได้ในขณะนี้ ลองใหม่อีกครั้งภายหลัง', 'ai_unavailable', 503);
        }

        return response()->json(['data' => $this->keys->status($request->user())]);
    }

    /**
     * DELETE /api/v1/me/ai-key: answers still waiting in the queue then use
     * the server key when there is one.
     */
    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('manage', TeacherApiKey::class);

        $this->keys->delete($request->user());

        return response()->json(['data' => $this->keys->status($request->user())]);
    }
}
