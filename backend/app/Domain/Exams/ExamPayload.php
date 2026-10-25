<?php

namespace App\Domain\Exams;

use App\Http\Resources\AssignmentResource;
use App\Models\Assignment;
use App\Models\ExamPageImage;
use App\Models\ExamSection;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Support\Collection;

/**
 * JSON of an exam for the teacher's app (DESIGN §22.15):
 *
 * GET /exams/{id} -> {exam, sections: [{id, position, title, instructions,
 *   type, option_count, numeric: {digits, allow_negative, allow_decimal}|null,
 *   default_points, first_number, last_number, questions: [question]}],
 *   key_complete, incomplete_questions: [{question_id, position, reasons[]}],
 *   booklet_incomplete_questions: [...], versions_ready, structure_locked_at,
 *   sheet: {pages, overflow}, page_images: [{id, source_document_id,
 *   page_no, width_px, height_px, available}], figures_pending: [{
 *   source_document_id, page_no, original_name, mime_type, figures,
 *   reason: needs_render|document_missing}]}
 *
 * question: {id, section_id, position (the number on the ก version),
 *   type, prompt_text, has_prompt_image, max_points, options: [{id,
 *   position, label, text, has_image, figure_source, figure_pending}],
 *   answer_key, approved_at, origin, blank, lock_options,
 *   lock_options_suggested, skill_ids, key_complete, has_prompt,
 *   figure_source, figure_pending, updated_at}
 *
 * figure_source: {page_image_id|null, source_document_id, page_no, box_2d}
 * of a figure cropped (or waiting to be cropped, figure_pending) from the
 * exam file (DESIGN §22.4); null for a picture the teacher attached.
 * lock_options_suggested: the detector or Gemini's reading suggests
 * "ห้ามสลับตัวเลือก" and the teacher has not set it.
 *
 * reasons: not_approved | no_key | no_prompt. blank = a question created
 * empty that the teacher has not filled in yet ("ยังไม่ได้กรอก").
 */
final class ExamPayload
{
    /**
     * @return array<string, mixed>
     */
    public static function of(Assignment $exam): array
    {
        $exam->load(['classroom', 'subject', 'course', 'lessonPlan']);
        $sections = ExamSection::query()->where('assignment_id', $exam->id)->orderBy('position')->get();
        $questions = Question::query()->where('assignment_id', $exam->id)
            ->with(['options', 'skills:id', 'section'])
            ->orderBy('position')
            ->get();
        $bySection = $questions->groupBy('section_id');
        $numeric = $questions->where('type', Question::TYPE_NUMERIC)->count();

        return [
            'exam' => (new AssignmentResource($exam))->resolve(),
            'sections' => $sections->map(fn (ExamSection $s) => self::section($s, $bySection->get($s->id) ?? collect()))->values()->all(),
            'key_complete' => $questions->isNotEmpty() && ExamKeyCheck::keyProblems($exam, $questions) === [],
            'incomplete_questions' => ExamKeyCheck::keyProblems($exam, $questions),
            'booklet_incomplete_questions' => ExamKeyCheck::bookletProblems($exam, $questions),
            'versions_ready' => ExamVersions::ready($exam),
            'structure_locked_at' => $exam->structure_locked_at?->toIso8601String(),
            'sheet' => ExamSheetCapacity::of($questions->count() - $numeric, $numeric, ExamSheetCapacity::reservedRows($exam)),
            'page_images' => ExamPageImage::query()->where('assignment_id', $exam->id)->orderBy('source_document_id')->orderBy('page_no')->get()
                ->map(fn (ExamPageImage $p) => $p->toApi())->values()->all(),
            'figures_pending' => ExamFigures::pending($exam),
        ];
    }

    /**
     * @param  Collection<int, Question>|null  $questions  defaults to the section's questions
     * @return array<string, mixed>
     */
    public static function section(ExamSection $section, ?Collection $questions = null): array
    {
        $questions ??= Question::query()->where('section_id', $section->id)->with(['options', 'skills:id', 'section'])->orderBy('position')->get();

        return [
            'id' => $section->id,
            'assignment_id' => $section->assignment_id,
            'position' => $section->position,
            'title' => $section->title,
            'instructions' => $section->instructions,
            'type' => $section->type,
            'option_count' => $section->type === ExamSection::TYPE_MCQ ? $section->option_count : null,
            'numeric' => $section->type === ExamSection::TYPE_NUMERIC ? [
                'digits' => $section->numeric_digits,
                'allow_negative' => $section->numeric_allow_negative,
                'allow_decimal' => $section->numeric_allow_decimal,
            ] : null,
            'default_points' => $section->default_points,
            'question_count' => $questions->count(),
            'first_number' => $questions->isEmpty() ? null : (int) $questions->first()->position,
            'last_number' => $questions->isEmpty() ? null : (int) $questions->last()->position,
            'questions' => $questions->map(fn (Question $q) => self::question($q))->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function question(Question $question): array
    {
        $question->loadMissing(['options', 'skills:id', 'section']);
        $options = $question->options;

        return [
            'id' => $question->id,
            'assignment_id' => $question->assignment_id,
            'section_id' => $question->section_id,
            'position' => (int) $question->position,
            'type' => $question->type,
            'prompt_text' => $question->prompt_text,
            'has_prompt_image' => $question->prompt_image_path !== null,
            'max_points' => (float) $question->max_points,
            'options' => $options->map(fn (QuestionOption $o) => [
                'id' => $o->id,
                'position' => $o->position,
                'label' => QuestionOption::label($o->position),
                'text' => $o->text,
                'has_image' => $o->image_path !== null,
                'figure_source' => self::figureSource($o->figure_source),
                'figure_pending' => ExamFigures::isPending($o->figure_source),
            ])->values()->all(),
            'answer_key' => $question->answer_key,
            'approved_at' => $question->approved_at?->toIso8601String(),
            'origin' => $question->origin,
            'blank' => $question->approved_at === null && $question->origin === Question::ORIGIN_TEACHER,
            'lock_options' => (bool) $question->lock_options,
            'lock_options_suggested' => $question->type === Question::TYPE_MCQ && ! $question->lock_options
                && ($question->lock_options_suggested || LockOptionsDetector::suggests($options->pluck('text')->all())),
            'skill_ids' => $question->skills->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'key_complete' => ExamKeyCheck::hasKey($question),
            'has_prompt' => ExamKeyCheck::hasPrompt($question),
            'figure_source' => self::figureSource($question->figure_source),
            'figure_pending' => ExamFigures::isPending($question->figure_source),
            'updated_at' => $question->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $source
     * @return array{page_image_id: int|null, source_document_id: int|null, page_no: int|null, box_2d: list<int>|null}|null
     */
    private static function figureSource(?array $source): ?array
    {
        if ($source === null) {
            return null;
        }

        return [
            'page_image_id' => isset($source['page_image_id']) ? (int) $source['page_image_id'] : null,
            'source_document_id' => isset($source['source_document_id']) ? (int) $source['source_document_id'] : null,
            'page_no' => isset($source['page_no']) ? (int) $source['page_no'] : null,
            'box_2d' => is_array($source['box_2d'] ?? null) ? array_values(array_map('intval', $source['box_2d'])) : null,
        ];
    }

    /**
     * GET /exams/{id}/versions -> {version_count, shuffle_nonce,
     * structure_locked_at, versions_ready, versions: [{version_no, label,
     * seed, question_order, items: [{sheet_no, question_id,
     * original_position, section_id, type, points, option_order|null,
     * accepted_options|accepted_values}]}]}. option_order lists the original
     * positions by displayed position (null = not shuffled);
     * accepted_options are the displayed positions of that version.
     *
     * @param  Collection<int, ExamVersion>  $versions
     * @return array<string, mixed>
     */
    public static function versions(Assignment $exam, Collection $versions): array
    {
        $questions = Question::query()->where('assignment_id', $exam->id)->with('section')->get()->keyBy('id');

        return [
            'version_count' => $exam->version_count,
            'shuffle_nonce' => $exam->shuffle_nonce,
            'structure_locked_at' => $exam->structure_locked_at?->toIso8601String(),
            'versions_ready' => ExamVersions::ready($exam),
            'versions' => $versions->map(fn (ExamVersion $v) => [
                'version_no' => $v->version_no,
                'label' => ExamVersions::label($v->version_no),
                'seed' => $v->seed,
                'question_order' => array_values(array_map('intval', $v->question_order)),
                'items' => ExamVersions::keyOf($v, $questions),
            ])->values()->all(),
        ];
    }
}
