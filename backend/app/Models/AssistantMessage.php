<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * DESIGN §37: one message of an assistant conversation.
 *
 * @property int $id
 * @property int $conversation_id
 * @property string $role user|assistant|error
 * @property string $content
 * @property string|null $error_code AiErrorCode of a failed answer
 * @property int|null $ai_call_id the ai_calls row of the answer (or the failure)
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property array<string, mixed>|null $plan the plan card of an answer (DESIGN §37.6, AssistantPlans)
 * @property string|null $plan_status proposed|applied|replaced
 * @property list<array{id: int, name: string, mime_type: string, page_count: int}>|null $attachments the teacher's files of a question (DESIGN §37.10)
 * @property Carbon $created_at
 */
class AssistantMessage extends Model
{
    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_ERROR = 'error';

    public const PLAN_PROPOSED = 'proposed';

    public const PLAN_APPLIED = 'applied';

    /** A newer card of the same conversation took its place before it was applied. */
    public const PLAN_REPLACED = 'replaced';

    public const UPDATED_AT = null;

    protected $fillable = ['conversation_id', 'role', 'content', 'error_code', 'ai_call_id', 'input_tokens', 'output_tokens', 'plan', 'plan_status', 'attachments'];

    protected function casts(): array
    {
        return ['plan' => 'array', 'attachments' => 'array', 'created_at' => 'datetime', 'ai_call_id' => 'integer', 'input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }

    /** @return BelongsTo<AssistantConversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AssistantConversation::class, 'conversation_id');
    }

    /** @return array<string, mixed> */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'role' => $this->role,
            'content' => $this->content,
            'error_code' => $this->error_code,
            'ai_call_id' => $this->ai_call_id,
            'plan' => $this->plan,
            'plan_status' => $this->plan_status,
            'attachments' => $this->attachments,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
