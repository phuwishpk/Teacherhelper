<?php

namespace App\Domain\Analysis;

use App\Domain\Courses\IndicatorMatcher;
use App\Domain\Gemini\GeminiCall;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiRequest;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\ResponseSchemas;
use App\Domain\Gemini\RubricDraftRequest;
use App\Domain\Gemini\TeacherGuidance;

/**
 * The `student_analysis` call (DESIGN §20.5, §21.6: thinking low, 1,536
 * output tokens): one call per student gives the teacher text and the
 * student text together.
 *
 * The input is the grade, the subject, and the indicators with their
 * mastery, n_obs and practice count (AnalysisInput::promptIndicators);
 * never the student's name, number or id, in the prompt or in the hints.
 *
 * The check after the schema: both texts non-empty (cut to their limits),
 * the student text never says "อ่อน" (invalid output, so the gateway asks
 * once more), and next_step_skill_codes become skill ids of the input's
 * indicators that have approved practice (others are dropped, at most 3).
 *
 * $guidance: the teacher's guidance of "วิเคราะห์ตอนนี้" (DESIGN §21.12) in
 * the {teacher_guidance} slot; the nightly batch never has one.
 */
final class StudentAnalysisRequests
{
    public const PURPOSE = 'student_analysis';

    public const TYPE = 'general';

    /** The word the student text must never contain (§20.5). */
    public const FORBIDDEN_STUDENT_WORD = 'อ่อน';

    public const MAX_TEACHER_CHARS = 2000;

    public const MAX_STUDENT_CHARS = 1000;

    public const MAX_NEXT_STEPS = 3;

    /** Indicators sent per student: the weakest and the strongest when a scope is very large. */
    public const MAX_INDICATORS = 60;

    public function __construct(private readonly PromptRepository $prompts) {}

    public function call(AnalysisInput $input, string $feature, ?string $guidance = null, ?int $guidanceBy = null): GeminiCall
    {
        $prompt = $this->prompts->get(self::PURPOSE, self::TYPE);
        $indicators = $input->promptIndicators();
        if (count($indicators) > self::MAX_INDICATORS) {
            $half = intdiv(self::MAX_INDICATORS, 2);
            $indicators = [...array_slice($indicators, 0, $half), ...array_slice($indicators, -$half)];
        }
        $skills = $input->skills();
        $codes = fn (array $items) => array_values(array_map(fn (array $i) => $skills[$i['skill_id']]->code, $items));
        $strengths = $codes($input->strengths());
        $areas = $codes($input->areas());

        return new GeminiCall(
            request: new GeminiRequest(
                purpose: self::PURPOSE,
                type: self::TYPE,
                promptVersion: $prompt->versionLabel(),
                systemInstruction: $prompt->renderSystem(),
                userText: $prompt->renderUser([
                    'grade_label' => RubricDraftRequest::gradeLabel(max(1, $input->gradeLevel)),
                    'subject' => $input->subject,
                    'indicators_json' => (string) json_encode($indicators, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'strength_codes' => $strengths === [] ? '-' : implode(', ', $strengths),
                    'area_codes' => $areas === [] ? '-' : implode(', ', $areas),
                    'teacher_guidance' => TeacherGuidance::block($guidance),
                ]),
                responseSchema: ResponseSchemas::get(self::PURPOSE, self::TYPE),
                temperature: $prompt->temperature,
                hints: [
                    // FakeGeminiClient reads its markers from question_text.
                    'question_text' => $input->subject."\n".implode("\n", array_column($indicators, 'name')),
                    'indicators' => $indicators,
                    'strength_codes' => $strengths,
                    'area_codes' => $areas,
                ],
                thinkingLevel: $prompt->thinking,
                maxOutputTokens: $prompt->maxOutputTokens,
            ),
            check: fn (array $data) => self::check($data, $input),
            feature: $feature,
            guidance: $guidance,
            guidanceBy: $guidanceBy,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{teacher_text: string, student_text: string, next_step_skill_ids: list<int>}
     *
     * @throws GeminiException invalid_output
     */
    public static function check(array $data, AnalysisInput $input): array
    {
        $teacher = trim((string) ($data['teacher_text'] ?? ''));
        $student = trim((string) ($data['student_text'] ?? ''));
        if ($teacher === '' || $student === '') {
            throw GeminiException::invalidOutput('an analysis text is empty');
        }
        if (str_contains($student, self::FORBIDDEN_STUDENT_WORD)) {
            throw GeminiException::invalidOutput('the student text uses the word "อ่อน"');
        }

        $exact = [];
        $normalised = [];
        foreach ($input->entries as $entry) {
            if ($entry['practice_items'] < 1) {
                continue;
            }
            $exact[$entry['skill']->code] ??= $entry['skill']->id;
            $normalised[IndicatorMatcher::normalize($entry['skill']->code)] ??= $entry['skill']->id;
        }
        $next = [];
        foreach ((array) ($data['next_step_skill_codes'] ?? []) as $code) {
            $code = trim((string) $code);
            $id = $exact[$code] ?? $normalised[IndicatorMatcher::normalize($code)] ?? null;
            if ($id !== null && ! in_array($id, $next, true) && count($next) < self::MAX_NEXT_STEPS) {
                $next[] = $id;
            }
        }

        return [
            'teacher_text' => self::cut($teacher, self::MAX_TEACHER_CHARS),
            'student_text' => self::cut($student, self::MAX_STUDENT_CHARS),
            'next_step_skill_ids' => $next,
        ];
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1).'…' : $text;
    }
}
