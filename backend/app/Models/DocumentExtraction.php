<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §19.8 `document_extractions`, §21.2: what Gemini read from a
 * document, read once and reused by every teacher of the school. The key is
 * (school_id, input_hash, purpose): input_hash is the file's SHA-256, or the
 * SHA-256 of the sorted file hashes plus the page range (AnswerKeyInputs).
 *
 * purpose `answer_key` (build step 3) holds both kinds of key result, told
 * apart by their input_hash: answer_key_read (the teacher's key) hashes the
 * files as above; answer_key_draft (AI drafts the answers) hashes a
 * "draft|" prefix, so the same question sheet never returns a teacher's key
 * for a draft or the other way round. result: AnswerKeyResult::toArray().
 *
 * guidance (DESIGN §21.12): the teacher's guidance the read or draft was
 * made with. It is part of input_hash (TeacherGuidance::cacheKey), so the
 * same files with other guidance are another row, and a row without
 * guidance keeps the key it had before guidance existed.
 *
 * @property int $id
 * @property int $school_id
 * @property string $input_hash
 * @property string $purpose answer_key|coursework|course|lesson_plan|exam
 * @property string $status queued|done|failed
 * @property array<string, mixed>|null $result
 * @property string|null $model
 * @property string|null $prompt_version
 * @property int $requested_by
 * @property string|null $error
 * @property string|null $guidance
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentExtraction extends Model
{
    public const PURPOSE_ANSWER_KEY = 'answer_key';

    /** An exam file read into sections and questions (DESIGN §22.4, ExamDocumentResult). */
    public const PURPOSE_EXAM = 'exam';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_DONE = 'done';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'school_id',
        'input_hash',
        'purpose',
        'status',
        'result',
        'model',
        'prompt_version',
        'requested_by',
        'error',
        'guidance',
    ];

    protected $attributes = [
        'status' => self::STATUS_QUEUED,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isDone(): bool
    {
        return $this->status === self::STATUS_DONE;
    }

    /**
     * guidance: what the teacher told the AI for this read ("คำแนะนำที่ใช้").
     *
     * @return array{id: int, purpose: string, status: string, error: string|null, guidance: string|null, created_at: string|null, updated_at: string|null}
     */
    public function toApi(): array
    {
        return [
            'id' => $this->id,
            'purpose' => $this->purpose,
            'status' => $this->status,
            'error' => $this->error,
            'guidance' => $this->guidance,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
