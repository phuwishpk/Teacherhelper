<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §8.4 `ai_calls`: one Gemini request (tokens, latency, outcome) and
 * whose key paid for it (key_source, §10.1). Never holds images or prompt
 * text, except the teacher's own guidance the call carried
 * (teacher_guidance, guidance_by; §21.12). No updated_at.
 *
 * @property int $id
 * @property string $purpose extract|extract_batch|extract_page|rubric_draft|explanation|practice_gen|... (§21.8)
 * @property int|null $response_id
 * @property int|null $question_id
 * @property int|null $skill_id
 * @property string $model
 * @property string $prompt_version
 * @property string $key_source teacher|server
 * @property int|null $input_tokens
 * @property int|null $output_tokens
 * @property int|null $latency_ms
 * @property string $status ok|error|invalid_output
 * @property string|null $error
 * @property string|null $feature grading_crop, grading_page, ... (§21.8)
 * @property int|null $cached_tokens
 * @property int|null $thinking_tokens
 * @property string|null $media_resolution low|medium|high|ultra_high|mixed
 * @property int|null $image_count
 * @property int|null $question_count
 * @property int|null $assignment_id
 * @property bool $batch
 * @property string|null $teacher_guidance
 * @property int|null $guidance_by
 * @property Carbon $created_at
 */
class AiCall extends Model
{
    public const UPDATED_AT = null;

    public const KEY_SOURCE_TEACHER = 'teacher';

    public const KEY_SOURCE_SERVER = 'server';

    public const STATUS_OK = 'ok';

    public const STATUS_ERROR = 'error';

    public const STATUS_INVALID_OUTPUT = 'invalid_output';

    protected $fillable = [
        'purpose',
        'response_id',
        'question_id',
        'skill_id',
        'model',
        'prompt_version',
        'key_source',
        'input_tokens',
        'output_tokens',
        'latency_ms',
        'status',
        'error',
        'feature',
        'cached_tokens',
        'thinking_tokens',
        'media_resolution',
        'image_count',
        'question_count',
        'assignment_id',
        'batch',
        'teacher_guidance',
        'guidance_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'latency_ms' => 'integer',
            'cached_tokens' => 'integer',
            'thinking_tokens' => 'integer',
            'image_count' => 'integer',
            'question_count' => 'integer',
            'batch' => 'boolean',
            'guidance_by' => 'integer',
        ];
    }

    /** @return BelongsTo<Response, $this> */
    public function response(): BelongsTo
    {
        return $this->belongsTo(Response::class);
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }

    /** @return BelongsTo<Skill, $this> */
    public function skill(): BelongsTo
    {
        return $this->belongsTo(Skill::class);
    }
}
