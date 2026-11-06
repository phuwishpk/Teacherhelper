<?php

namespace App\Domain\Training;

use App\Domain\Gemini\PromptText;
use App\Domain\Grading\AnswerMatcher;
use App\Domain\Scans\ScanFiles;
use App\Models\Question;
use App\Models\Response;
use App\Models\TrainingSample;
use Illuminate\Support\Facades\Log;

/**
 * Teacher corrections as training data for the digit model (DESIGN §8.6,
 * §12.3 item 3): when a teacher overrides the score of a numeric answer in
 * a school with allow_training_data = TRUE, the answer crop is copied to
 * training/{school}/{response}.webp (outside the crop retention policy of
 * §7.3) and stored with its label, one sample per response.
 *
 * The label is the teacher's own reading when the PATCH carries
 * `answer_text`; otherwise, when the override gives full marks, the key's
 * numeric value (the teacher confirmed the student wrote the right number).
 * A partial override without a reading has no reliable label and is skipped.
 * writer_key is a hash of the student id: the split by writer never needs
 * the id itself.
 */
final class TrainingSamples
{
    public const DIRECTORY = 'training';

    public const MAX_LABEL = 32;

    /** The digit model's charset (§12.2): 0–9 . - / */
    private const LABEL_PATTERN = '/^[0-9.\-\/]{1,32}$/';

    /**
     * The label of a score override, or null when none can be derived.
     *
     * @param  string|null  $answerText  the teacher's reading of the box (PATCH answer_text)
     */
    public static function labelForOverride(Response $response, Question $question, float $newScore, ?string $answerText): ?string
    {
        if ($answerText !== null && trim($answerText) !== '') {
            return self::cleanLabel($answerText);
        }
        $numeric = self::numericKey($question);
        if ($numeric === null || $newScore < (float) $question->max_points - 0.001) {
            return null;
        }

        return self::cleanLabel(PromptText::number((float) $numeric['value']));
    }

    public function recordCorrection(Response $response, string $label): ?TrainingSample
    {
        $response->loadMissing(['question', 'submission.assignment.school']);
        $question = $response->question;
        $submission = $response->submission;
        $school = $submission?->assignment?->school;
        if ($question === null || $submission === null || $school === null || ! $school->allow_training_data) {
            return null;
        }
        $label = self::cleanLabel($label);
        if ($label === null || self::numericKey($question) === null) {
            return null;
        }

        $source = $question->type === Question::TYPE_SHOW_WORK ? $response->final_crop_path : $response->crop_path;
        $disk = ScanFiles::disk();
        if ($source === null || ! $disk->exists($source)) {
            return null;
        }

        $path = self::path($school->id, $response->id);
        if (! $disk->put($path, (string) $disk->get($source))) {
            Log::warning('training_sample.copy_failed', ['response_id' => $response->id]);

            return null;
        }

        return TrainingSample::query()->updateOrCreate(
            ['response_id' => $response->id],
            [
                'school_id' => $school->id,
                'source' => TrainingSample::SOURCE_TEACHER_CORRECTION,
                'crop_path' => $path,
                'label' => $label,
                'writer_key' => self::writerKey($submission->student_id),
            ],
        );
    }

    public static function path(int $schoolId, int $responseId): string
    {
        return self::DIRECTORY."/{$schoolId}/{$responseId}.webp";
    }

    public static function writerKey(int $studentId): string
    {
        return hash('sha256', 'student:'.$studentId);
    }

    /** Thai digits -> Arabic, spaces removed; null unless it is a digit-model string. */
    public static function cleanLabel(string $label): ?string
    {
        $clean = str_replace([' ', '−', ','], ['', '-', '.'], AnswerMatcher::normalize($label));

        return preg_match(self::LABEL_PATTERN, $clean) === 1 ? $clean : null;
    }

    /**
     * @return array{value: float|int, abs_tol?: float|int}|null
     */
    private static function numericKey(Question $question): ?array
    {
        $key = $question->answer_key ?? [];
        $numeric = match ($question->type) {
            Question::TYPE_SHORT => $key['numeric'] ?? null,
            Question::TYPE_SHOW_WORK => $key['final']['numeric'] ?? null,
            default => null,
        };

        return is_array($numeric) && is_numeric($numeric['value'] ?? null) ? $numeric : null;
    }
}
