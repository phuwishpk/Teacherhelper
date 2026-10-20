<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assistant\AssistantChat;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "ผู้ช่วย Krucheck", step 1 (DESIGN §37), the teacher's own conversations:
 *
 * - GET /assistant/status -> {data: {available, used_today, daily_limit}}
 * - GET /assistant/conversations -> {data: [conversation]} newest first
 * - GET /assistant/conversations/{id} -> {data: {conversation, messages}}
 * - DELETE /assistant/conversations/{id} -> 204
 * - POST /assistant/messages {text, conversation_id?} -> {data: {conversation, messages: [question, answer]}}
 * - POST /assistant/conversations/{id}/retry -> the same, for the question of a failed answer
 *
 * A failed answer is an AI error (AiErrors: ai_error_code, ai_call_id) with
 * `conversation` and `messages` (the question and the stored failure).
 */
class AssistantController extends Controller
{
    public function __construct(private readonly AssistantChat $chat) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->chat->status($request->user())]);
    }

    public function index(Request $request): JsonResponse
    {
        $rows = AssistantConversation::query()
            ->where('user_id', $request->user()->id)
            ->orderByDesc('updated_at')->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->json(['data' => $rows->map(fn ($c) => AssistantChat::conversationApi($c))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $conversation = self::own($request, $id);

        return response()->json(['data' => [
            'conversation' => AssistantChat::conversationApi($conversation),
            'messages' => $conversation->messages()->get()->map(fn (AssistantMessage $m) => $m->toApi())->values(),
        ]]);
    }

    public function destroy(Request $request, int $id): Response
    {
        self::own($request, $id)->delete();

        return response()->noContent();
    }

    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:'.AssistantChat::MAX_MESSAGE_LENGTH],
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ], [
            'text.required' => 'พิมพ์คำถามก่อน',
            'text.max' => 'ข้อความยาวเกิน '.AssistantChat::MAX_MESSAGE_LENGTH.' ตัวอักษร',
        ]);
        $conversation = isset($data['conversation_id']) ? self::own($request, (int) $data['conversation_id']) : null;

        return self::result($this->chat->send($request->user(), $conversation, (string) $data['text']));
    }

    public function retry(Request $request, int $id): JsonResponse
    {
        return self::result($this->chat->retry($request->user(), self::own($request, $id)));
    }

    /** @param  array{conversation: AssistantConversation, messages: list<AssistantMessage>}  $result */
    private static function result(array $result): JsonResponse
    {
        return response()->json(['data' => [
            'conversation' => AssistantChat::conversationApi($result['conversation']),
            'messages' => array_map(fn (AssistantMessage $m) => $m->toApi(), $result['messages']),
        ]]);
    }

    private static function own(Request $request, int $id): AssistantConversation
    {
        $conversation = AssistantConversation::query()->where('user_id', $request->user()->id)->find($id);
        if ($conversation === null) {
            throw new ApiException('ไม่พบบทสนทนานี้', 'not_found', 404);
        }

        return $conversation;
    }
}
