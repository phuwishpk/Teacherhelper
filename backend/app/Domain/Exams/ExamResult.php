<?php

namespace App\Domain\Exams;

use App\Http\Resources\AppealResource;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\Response;
use App\Models\Submission;
use Illuminate\Support\Collection;

/**
 * A published exam result as its student sees it (DESIGN §22.12, §22.15
 * GET /student/results/{submission_id}):
 *
 *   {kind: "exam", version_label, total, max,
 *    sections: [{title, score, max}],
 *    items: [...] | null}
 *
 * The score only: total and per section. `items` is null unless the teacher
 * turned on "ให้นักเรียนดูเฉลย" (show_key_to_students); then one item per
 * question in the order of the student's own version, with the labels that
 * version printed:
 *
 *   {response_id, number (on the sheet), section_title, type, prompt_text,
 *    max_points, score, marked: [labels] | null, marked_value, correct:
 *    [labels] | null, correct_values, appeal, can_appeal}
 *
 * No AI explanation and no page image (deleted after publishing).
 */
final class ExamResult
{
    /**
     * @return array<string, mixed>
     */
    public static function forStudent(Submission $submission, Assignment $exam): array
    {
        $questions = Question::query()->where('assignment_id', $exam->id)->with('section')->orderBy('position')->get();
        $responses = $submission->relationLoaded('responses')
            ? $submission->responses
            : Response::query()->where('submission_id', $submission->id)->with('appeal')->get();
        $byQuestion = $responses->keyBy('question_id');
        $versionNo = (int) ($responses->first(fn (Response $r) => is_array($r->exam_answer))?->exam_answer['version_no'] ?? 1);

        $sections = [];
        foreach (ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get() as $section) {
            $own = $questions->where('section_id', $section->id);
            $sections[] = [
                'title' => $section->title ?? 'ตอนที่ '.$section->position,
                'score' => round((float) $own->sum(fn (Question $q) => (float) $byQuestion->get($q->id)?->effectiveScore()), 2),
                'max' => round((float) $own->sum('max_points'), 2),
            ];
        }

        return [
            'kind' => Assignment::KIND_EXAM,
            'version_label' => (int) $exam->version_count > 1 ? ExamVersions::label($versionNo) : null,
            'total' => $submission->effectiveTotal(),
            'max' => round((float) $questions->sum('max_points'), 2),
            'sections' => $sections,
            'items' => $exam->show_key_to_students ? self::items($exam, $versionNo, $questions, $byQuestion) : null,
        ];
    }

    /**
     * @param  Collection<int, Question>  $questions
     * @param  Collection<int|string, Response>  $byQuestion
     * @return list<array<string, mixed>>
     */
    private static function items(Assignment $exam, int $versionNo, $questions, $byQuestion): array
    {
        $version = ExamVersions::stored($exam)->firstWhere('version_no', $versionNo);
        $order = $version?->question_order ?? $questions->pluck('id')->all();
        $orders = $version?->option_orders ?? [];
        $byId = $questions->keyBy('id');

        $items = [];
        foreach (array_values($order) as $index => $questionId) {
            /** @var Question|null $question */
            $question = $byId->get((int) $questionId);
            $response = $byQuestion->get((int) $questionId);
            if ($question === null || ! $response instanceof Response) {
                continue;
            }
            $optionOrder = isset($orders[$questionId]) ? array_map('intval', $orders[$questionId]) : null;
            $answer = ExamAnswerScore::effective($response->exam_answer);
            $key = is_array($question->answer_key) ? $question->answer_key : [];
            $numeric = $question->type === Question::TYPE_NUMERIC;
            $appeal = $response->appeal;
            $items[] = [
                'response_id' => $response->id,
                'number' => $index + 1,
                'section_title' => $question->section?->title,
                'type' => $question->type,
                'prompt_text' => $question->prompt_text,
                'max_points' => (float) $question->max_points,
                'score' => $response->effectiveScore(),
                'marked' => $numeric ? null : ExamAnswerScore::labelsOf($question, $answer['selected'], $optionOrder),
                'marked_value' => $numeric ? $answer['value'] : null,
                'correct' => $numeric ? null : ExamAnswerScore::labelsOf($question, array_map('intval', (array) ($key['accepted_options'] ?? [])), $optionOrder),
                'correct_values' => $numeric ? array_values(array_map('strval', (array) ($key['accepted_values'] ?? []))) : null,
                'appeal' => $appeal instanceof Appeal ? AppealResource::summary($appeal) : null,
                'can_appeal' => $appeal === null,
            ];
        }

        return $items;
    }
}
