<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * DESIGN §37: one conversation of a teacher with "ผู้ช่วย Krucheck".
 *
 * @property int $id
 * @property int $user_id
 * @property string $title from the first message
 * @property int $input_tokens summed over its AI calls
 * @property int $output_tokens
 * @property Carbon $created_at
 * @property Carbon $updated_at the last message; deleted 90 days after it
 */
class AssistantConversation extends Model
{
    /** Days after the last message before the conversation is deleted. */
    public const RETENTION_DAYS = 90;

    protected $fillable = ['user_id', 'title', 'input_tokens', 'output_tokens'];

    protected function casts(): array
    {
        return ['input_tokens' => 'integer', 'output_tokens' => 'integer'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AssistantMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class, 'conversation_id')->orderBy('id');
    }

    /** @return HasMany<AssistantStudentLabel, $this> */
    public function labels(): HasMany
    {
        return $this->hasMany(AssistantStudentLabel::class, 'conversation_id')->orderBy('id');
    }
}
