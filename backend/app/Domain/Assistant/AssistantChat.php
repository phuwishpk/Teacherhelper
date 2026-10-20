<?php

namespace App\Domain\Assistant;

use App\Domain\Gemini\AiErrorCode;
use App\Domain\Gemini\AiErrors;
use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Exceptions\ApiException;
use App\Models\AiCall;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * "ผู้ช่วย Krucheck", step 1 (DESIGN §37): a teacher asks, Gemini answers
 * from the knowledge, in the same request (no streaming on the hosting).
 *
 * - Only the teacher's own Gemini key, never the server key.
 * - DAILY_LIMIT messages a day per teacher (Bangkok day): 429
 *   assistant_limit with ai_error_code CHAT-LIMIT, nothing stored.
 * - The question is stored first; the answer, or the failure as an
 *   `error` message with its code and ai_calls id, after it. A failure
 *   answers like any AI error (AiErrors) plus the conversation and the
 *   stored messages, so the app can show them and offer "ลองใหม่".
 */
final class AssistantChat
{
    public const DAILY_LIMIT = 100;

    public const MAX_MESSAGE_LENGTH = 2000;

    /** Not an AI failure: the teacher's messages of the day are used up. */
    public const LIMIT_CODE = 'CHAT-LIMIT';

    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly GeminiGateway $gateway,
        private readonly AssistantRequests $requests,
    ) {}

    /** @return array{available: bool, used_today: int, daily_limit: int} */
    public function status(User $teacher): array
    {
        return [
            'available' => $this->keys->teacherKey($teacher->id) !== null,
            'used_today' => self::usedToday($teacher),
            'daily_limit' => self::DAILY_LIMIT,
        ];
    }

    /**
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>}
     *
     * @throws ApiException 422 ai_key_missing, 429 assistant_limit, 4xx/5xx of a failed AI call
     */
    public function send(User $teacher, ?AssistantConversation $conversation, string $text): array
    {
        $text = trim($text);
        $key = $this->guard($teacher);
        $conversation ??= AssistantConversation::create([
            'user_id' => $teacher->id,
            'title' => Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, 80, '…'),
        ]);
        $history = $conversation->messages()->get()->all();
        $question = $conversation->messages()->create(['role' => AssistantMessage::ROLE_USER, 'content' => $text]);

        return $this->answer($teacher, $conversation, $question, $history, $key);
    }

    /**
     * "ลองใหม่": the conversation ends with a failure; its question is asked
     * again and the failure is replaced by the new answer (or failure).
     *
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>}
     *
     * @throws ApiException 409 nothing_to_retry, and those of send()
     */
    public function retry(User $teacher, AssistantConversation $conversation): array
    {
        $messages = $conversation->messages()->get();
        $last = $messages->last();
        $question = $messages->where('role', AssistantMessage::ROLE_USER)->last();
        if ($last === null || $last->role !== AssistantMessage::ROLE_ERROR || $question === null) {
            throw new ApiException('ไม่มีข้อความที่ต้องลองใหม่', 'nothing_to_retry', 409);
        }
        $key = $this->guard($teacher);
        $last->delete();
        $history = $messages->filter(fn (AssistantMessage $m) => $m->id < $question->id)->values()->all();

        return $this->answer($teacher, $conversation, $question, $history, $key);
    }

    /**
     * @param  list<AssistantMessage>  $history
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>}
     */
    private function answer(User $teacher, AssistantConversation $conversation, AssistantMessage $question, array $history, GeminiKey $key): array
    {
        $call = $this->requests->call($question->content, $history, $conversation->id);
        $outcome = $this->gateway->run(['chat' => $call], $key)['chat'];
        $usage = $outcome->aiCallId === null ? null : AiCall::query()->find($outcome->aiCallId, ['input_tokens', 'output_tokens']);
        $input = (int) ($usage->input_tokens ?? 0);
        $output = (int) ($usage->output_tokens ?? 0);

        $reply = DB::transaction(function () use ($conversation, $outcome, $input, $output) {
            $reply = $conversation->messages()->create($outcome->isOk()
                ? [
                    'role' => AssistantMessage::ROLE_ASSISTANT,
                    'content' => (string) ($outcome->data['answer'] ?? ''),
                    'ai_call_id' => $outcome->aiCallId,
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                ]
                : [
                    'role' => AssistantMessage::ROLE_ERROR,
                    'content' => AiErrorCode::message($outcome->errorCode ?? AiErrorCode::ERROR),
                    'error_code' => $outcome->errorCode ?? AiErrorCode::ERROR,
                    'ai_call_id' => $outcome->aiCallId,
                    'input_tokens' => $input,
                    'output_tokens' => $output,
                ]);
            $conversation->forceFill([
                'input_tokens' => $conversation->input_tokens + $input,
                'output_tokens' => $conversation->output_tokens + $output,
                'updated_at' => now(),
            ])->save();

            return $reply;
        });

        if (! $outcome->isOk()) {
            throw self::failure($outcome, $conversation, $question, $reply);
        }

        return ['conversation' => $conversation->refresh(), 'messages' => [$question, $reply]];
    }

    private static function failure(CallOutcome $outcome, AssistantConversation $conversation, AssistantMessage $question, AssistantMessage $reply): ApiException
    {
        $error = AiErrors::fromOutcome($outcome, 'ผู้ช่วยตอบไม่สำเร็จ');

        return new ApiException($error->getMessage(), $error->errorCode, $error->status, [], $error->extra + [
            'conversation' => self::conversationApi($conversation->refresh()),
            'messages' => [$question->toApi(), $reply->toApi()],
        ]);
    }

    /** The teacher's own key, after the daily limit. */
    private function guard(User $teacher): GeminiKey
    {
        if (self::usedToday($teacher) >= self::DAILY_LIMIT) {
            throw new ApiException(
                'วันนี้ถามผู้ช่วยครบ '.self::DAILY_LIMIT.' ข้อความแล้ว ถามต่อได้พรุ่งนี้ ระหว่างนี้ใช้ช่องค้นหาเมนูหรือคู่มือการใช้งานได้',
                'assistant_limit',
                429,
                [],
                ['ai_error_code' => self::LIMIT_CODE],
            );
        }
        $key = $this->keys->teacherKey($teacher->id);
        if ($key === null) {
            throw new ApiException(AiErrorCode::message(AiErrorCode::KEY_MISSING), 'ai_key_missing', 422);
        }

        return $key;
    }

    public static function usedToday(User $teacher, ?CarbonInterface $now = null): int
    {
        $start = ($now ?? now())->copy()->timezone('Asia/Bangkok')->startOfDay()->utc();

        return AssistantMessage::query()
            ->where('role', AssistantMessage::ROLE_USER)
            ->where('created_at', '>=', $start)
            ->whereIn('conversation_id', AssistantConversation::query()->select('id')->where('user_id', $teacher->id))
            ->count();
    }

    /** @return array<string, mixed> */
    public static function conversationApi(AssistantConversation $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'input_tokens' => $c->input_tokens,
            'output_tokens' => $c->output_tokens,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /** Deletes the conversations untouched for RETENTION_DAYS; returns how many. */
    public static function purge(): int
    {
        return AssistantConversation::query()
            ->where('updated_at', '<', now()->subDays(AssistantConversation::RETENTION_DAYS))
            ->delete();
    }
}
