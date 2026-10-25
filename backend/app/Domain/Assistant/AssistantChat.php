<?php

namespace App\Domain\Assistant;

use App\Domain\Documents\DocumentSelection;
use App\Domain\Gemini\AiErrorCode;
use App\Domain\Gemini\AiErrors;
use App\Domain\Gemini\CallOutcome;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiImage;
use App\Domain\Gemini\GeminiKey;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\MediaResolution;
use App\Exceptions\ApiException;
use App\Models\AiCall;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Models\SourceDocument;
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

    /** The answer to a typed confirmation while a card is open (DESIGN §37.6). */
    public const APPLYING = 'กำลังสร้างข้อที่ติ๊กไว้ในการ์ด ดูผลที่การ์ดด้านบน';

    /** The answer to a typed confirmation with no open card. */
    public const NO_OPEN_CARD = 'ตอนนี้ไม่มีการ์ดที่รอสร้าง บอกได้เลยว่าอยากให้ออกข้ออะไร ลงงานไหน';

    /** Files at most per question. */
    public const MAX_ATTACHMENTS = 5;

    /** Pages the chat reads itself; longer files go to the queued readers. */
    public const MAX_READ_PAGES = 10;

    public const FILE_TOO_LONG = 'ไฟล์ยาวเกิน 10 หน้า ผู้ช่วยอ่านโจทย์เองไม่ได้ แบ่งไฟล์ให้สั้นลง หรือให้ผู้ช่วยส่งไฟล์ไปอ่านเป็นเฉลยหรือข้อสอบแทน';

    /** The answer when Gemini asked for data the teacher has not turned on. */
    public const DATA_NOT_ON = 'ผู้ช่วยยังดูคะแนนนักเรียนไม่ได้ กด «ข้อมูลนักเรียน» ใต้ช่องพิมพ์ อ่านคำเตือนแล้วกด "เข้าใจแล้ว" จากนั้นถามอีกครั้ง';

    /** The admin switched student data off for the teacher's workspace. */
    public const DATA_OFF = 'ผู้ดูแลระบบปิดการถามข้อมูลนักเรียนกับผู้ช่วยไว้สำหรับโรงเรียนนี้';

    /** Not an AI failure: the teacher's messages of the day are used up. */
    public const LIMIT_CODE = 'CHAT-LIMIT';

    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly GeminiGateway $gateway,
        private readonly AssistantRequests $requests,
        private readonly AssistantStudents $students,
        private readonly AssistantStudentData $data,
    ) {}

    /** @return array{available: bool, used_today: int, daily_limit: int} */
    public function status(User $teacher): array
    {
        return [
            'available' => $this->keys->teacherKey($teacher->id) !== null,
            'used_today' => self::usedToday($teacher),
            'daily_limit' => self::DAILY_LIMIT,
            'student_data' => [
                'allowed' => AssistantStudentData::allowed($teacher),
                'acknowledged' => $teacher->assistant_data_ack_at !== null,
            ],
        ];
    }

    /** "เข้าใจแล้ว" on the student-data notice (DESIGN §37.8). */
    public function acknowledge(User $teacher): void
    {
        if (! AssistantStudentData::allowed($teacher)) {
            throw new ApiException(self::DATA_OFF, 'student_data_off', 403);
        }
        $teacher->forceFill(['assistant_data_ack_at' => $teacher->assistant_data_ack_at ?? now()])->save();
    }

    /**
     * @param  array<string, mixed>|null  $scope  the student data the teacher chose (AssistantStudentData::build)
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>, apply_message_id: int|null}
     *
     * @throws ApiException 422 ai_key_missing, 429 assistant_limit, 403 student_data_off,
     *                      409 student_data_ack_required, 404 of the scope, 4xx/5xx of a failed AI call
     */
    public function send(User $teacher, ?AssistantConversation $conversation, string $text, ?array $scope = null, array $documentIds = []): array
    {
        $text = trim($text);
        $key = $this->guard($teacher);
        // The teacher's own files only, within the readers' limits (DESIGN §37.10).
        $attachments = $documentIds === [] ? null : array_map(
            fn (SourceDocument $d) => ['id' => $d->id, 'name' => $d->original_name, 'mime_type' => $d->mime_type, 'page_count' => (int) $d->page_count],
            DocumentSelection::resolve($teacher, ['document_ids' => $documentIds])->documents,
        );
        if ($scope !== null) {
            self::assertStudentData($teacher);
        }
        $new = $conversation === null;
        $conversation ??= AssistantConversation::create([
            'user_id' => $teacher->id,
            'title' => Str::limit(preg_replace('/\s+/u', ' ', $text) ?? $text, 80, '…'),
        ]);
        try {
            $data = $scope === null ? null : $this->data->build($teacher, $conversation, $scope);
        } catch (ApiException $e) {
            if ($new) {
                $conversation->delete();
            }
            throw $e;
        }
        $history = $conversation->messages()->get()->all();
        $question = $conversation->messages()->create(['role' => AssistantMessage::ROLE_USER, 'content' => $text, 'attachments' => $attachments]);

        return $this->answer($teacher, $conversation, $question, $history, $key, $data);
    }

    /** @throws ApiException 403 student_data_off, 409 student_data_ack_required */
    private static function assertStudentData(User $teacher): void
    {
        if (! AssistantStudentData::allowed($teacher)) {
            throw new ApiException(self::DATA_OFF, 'student_data_off', 403);
        }
        if ($teacher->assistant_data_ack_at === null) {
            throw new ApiException('กด "เข้าใจแล้ว" ที่คำเตือนเรื่องข้อมูลนักเรียนก่อน', 'student_data_ack_required', 409);
        }
    }

    /**
     * "ลองใหม่": the conversation ends with a failure; its question is asked
     * again and the failure is replaced by the new answer (or failure).
     *
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>, apply_message_id: int|null}
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
     * @return array{conversation: AssistantConversation, messages: list<AssistantMessage>, apply_message_id: int|null}
     */
    private function answer(User $teacher, AssistantConversation $conversation, AssistantMessage $question, array $history, GeminiKey $key, ?string $data = null): array
    {
        $previousTarget = null;
        $previousCard = null;
        foreach (array_reverse($history) as $m) {
            if (is_array($m->plan)) {
                $previousTarget = $m->plan['result']['assignment_id'] ?? $m->plan['target']['assignment_id'] ?? null;
                $previousCard = $m->plan['kind'] ?? AssistantPlans::CARD_QUESTIONS;
                break;
            }
        }
        // Gemini sees labels, never the names the teacher typed (DESIGN §37.8).
        $asked = $this->students->pseudonymise($teacher, $conversation, $question->content);
        $turns = array_map(function (AssistantMessage $m) use ($teacher, $conversation) {
            if ($m->role !== AssistantMessage::ROLE_USER) {
                return $m;
            }
            $copy = clone $m;
            $copy->content = $this->students->pseudonymise($teacher, $conversation, $m->content);

            return $copy;
        }, $history);
        $files = self::latestAttachments([...$history, $question]);
        $context = AssistantContext::build($teacher, $question->content, $previousTarget === null ? null : (int) $previousTarget, $previousCard)
            ."\n\n".self::fileNote($files)
            ."\n\n".self::dataNote($teacher, $data !== null)
            .($data === null ? '' : "\n\n".$data);
        $outcome = $this->gateway->run(['chat' => $this->requests->call($asked, $turns, $conversation->id, $context)], $key)['chat'];
        $calls = [$outcome->aiCallId];

        // The answer asked for data: fetch it and ask again, one message for the teacher.
        $need = $outcome->isOk() && $data === null && ! is_array($outcome->data['plan'] ?? null) ? ($outcome->data['need_data'] ?? null) : null;
        $blocked = is_array($need) && ! AssistantStudentData::enabled($teacher);
        if (is_array($need) && in_array($need['set'] ?? null, AssistantStudentData::SETS, true) && ! $blocked) {
            try {
                $fetched = $this->data->build($teacher, $conversation, $need);
            } catch (ApiException $e) {
                $fetched = "# STUDENT DATA\n(not available: {$e->getMessage()})";
            }
            $again = $context."\n\n".$fetched."\n\nThe STUDENT DATA above is what you asked for: answer the teacher now and do not set need_data.";
            $outcome = $this->gateway->run(['chat' => $this->requests->call($asked, $turns, $conversation->id, $again)], $key)['chat'];
            $calls[] = $outcome->aiCallId;
        }
        // The answer asked to read the attached files: read them and ask again.
        $fileBlocked = false;
        if ($outcome->isOk() && ($outcome->data['need_file'] ?? false) === true && ! is_array($outcome->data['plan'] ?? null) && $files !== []) {
            $pages = array_sum(array_column($files, 'page_count'));
            if ($pages > self::MAX_READ_PAGES) {
                $fileBlocked = true;
            } else {
                $level = MediaResolution::forPart(MediaResolution::PART_DOCUMENT);
                $parts = array_map(
                    fn (array $f) => new GeminiImage($f['bytes'], $f['mime_type'], $level),
                    DocumentSelection::resolve($teacher, ['document_ids' => array_column($files, 'id')])->files(),
                );
                $again = $context."\n\nThe ATTACHED FILES are included in this request: read them and answer now (make the card); do not set need_file.";
                $outcome = $this->gateway->run(['chat' => $this->requests->call($asked, $turns, $conversation->id, $again, $parts)], $key)['chat'];
                $calls[] = $outcome->aiCallId;
            }
        }
        $usage = AiCall::query()->whereIn('id', array_filter($calls))->get(['input_tokens', 'output_tokens']);
        $input = (int) $usage->sum('input_tokens');
        $output = (int) $usage->sum('output_tokens');

        $plan = $outcome->isOk() && is_array($outcome->data['plan'] ?? null) ? AssistantPlans::normalise($outcome->data['plan'], $teacher, $question->content) : null;
        // "โอเค สร้างเลย": the app applies the latest open card with its ticks.
        // The wording is fixed, so the answer never claims a result it has not seen.
        $applyId = null;
        $content = (string) ($outcome->data['answer'] ?? '');
        if ($blocked) {
            $content = AssistantStudentData::allowed($teacher) ? self::DATA_NOT_ON : self::DATA_OFF;
        }
        if ($fileBlocked) {
            $content = self::FILE_TOO_LONG;
        }
        if ($plan === null && ($outcome->data['apply_plan'] ?? false) === true) {
            $applyId = $conversation->messages()->where('plan_status', AssistantMessage::PLAN_PROPOSED)->max('id');
            $content = $applyId === null ? self::NO_OPEN_CARD : self::APPLYING;
        }

        $reply = DB::transaction(function () use ($conversation, $outcome, $input, $output, $plan, $content) {
            if ($plan !== null) {
                $conversation->messages()->where('plan_status', AssistantMessage::PLAN_PROPOSED)->update(['plan_status' => AssistantMessage::PLAN_REPLACED]);
            }
            $reply = $conversation->messages()->create($outcome->isOk()
                ? [
                    'role' => AssistantMessage::ROLE_ASSISTANT,
                    'content' => $content,
                    'plan' => $plan,
                    'plan_status' => $plan === null ? null : AssistantMessage::PLAN_PROPOSED,
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

        return ['conversation' => $conversation->refresh(), 'messages' => [$question, $reply], 'apply_message_id' => $applyId === null ? null : (int) $applyId];
    }

    private static function failure(CallOutcome $outcome, AssistantConversation $conversation, AssistantMessage $question, AssistantMessage $reply): ApiException
    {
        $error = AiErrors::fromOutcome($outcome, 'ผู้ช่วยตอบไม่สำเร็จ');

        return new ApiException($error->getMessage(), $error->errorCode, $error->status, [], $error->extra + [
            'conversation' => self::conversationApi($conversation->refresh()),
            'messages' => [$question->toApi(), $reply->toApi()],
            'labels' => AssistantStudents::api($conversation),
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

    /** What Gemini is told about student data in this answer. */
    private static function dataNote(User $teacher, bool $attached): string
    {
        return match (true) {
            $attached => '# STUDENT DATA ACCESS: the teacher chose the data below; answer from it.',
            ! AssistantStudentData::allowed($teacher) => '# STUDENT DATA ACCESS: off, the admin switched it off for this school. If asked about students\' results, say so and point to the screens (สมุดคะแนน, วิเคราะห์รายคน).',
            $teacher->assistant_data_ack_at === null => '# STUDENT DATA ACCESS: not turned on yet. If asked about students\' results, tell the teacher to press «ข้อมูลนักเรียน» under the chat box and accept the notice, then ask again.',
            default => '# STUDENT DATA ACCESS: on. To answer about results, set need_data (classroom_summary with classroom_id, student_history with student_label, assignment_analysis with assignment_id) and leave plan out; the data comes back in a second call.',
        };
    }

    /**
     * The files of the latest question that had some (DESIGN §37.10).
     *
     * @param  list<AssistantMessage>  $messages
     * @return list<array{id: int, name: string, mime_type: string, page_count: int}>
     */
    private static function latestAttachments(array $messages): array
    {
        foreach (array_reverse($messages) as $m) {
            if ($m->role === AssistantMessage::ROLE_USER && $m->attachments !== null && $m->attachments !== []) {
                return $m->attachments;
            }
        }

        return [];
    }

    /** @param  list<array{id: int, name: string, mime_type: string, page_count: int}>  $files */
    private static function fileNote(array $files): string
    {
        if ($files === []) {
            return '# ATTACHED FILES: none.';
        }

        return "# ATTACHED FILES (the teacher's documents; the content is not included yet):\n".implode("\n", array_map(
            fn (array $f) => "- {$f['id']} | {$f['name']} | {$f['mime_type']} | {$f['page_count']} pages",
            $files,
        ));
    }
}
