<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DESIGN §22.14 `question_options`: an option of an exam mcq question in the
 * original order (position 1–6 = ก–ฉ), with text and/or an image.
 *
 * @property int $id
 * @property int $question_id
 * @property int $position
 * @property string|null $text
 * @property string|null $image_path
 * @property array<string, mixed>|null $figure_source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class QuestionOption extends Model
{
    /** Labels of the positions 1–6 on the booklet and the answer sheet. */
    public const LABELS = [1 => 'ก', 2 => 'ข', 3 => 'ค', 4 => 'ง', 5 => 'จ', 6 => 'ฉ'];

    protected $fillable = ['question_id', 'position', 'text', 'image_path', 'figure_source'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'question_id' => 'integer',
            'position' => 'integer',
            'figure_source' => 'array',
        ];
    }

    public static function label(int $position): string
    {
        return self::LABELS[$position] ?? (string) $position;
    }

    /** @return BelongsTo<Question, $this> */
    public function question(): BelongsTo
    {
        return $this->belongsTo(Question::class);
    }
}
