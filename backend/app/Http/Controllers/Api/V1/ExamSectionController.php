<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamEditor;
use App\Domain\Exams\ExamPayload;
use App\Http\Controllers\Controller;
use App\Models\ExamSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Sections of the teacher's exams and their questions (DESIGN §22.2,
 * §22.15). A section of another teacher's exam is a 404.
 */
class ExamSectionController extends Controller
{
    public function __construct(private readonly ExamEditor $editor) {}

    /**
     * PATCH /api/v1/exam-sections/{id} {title?, instructions?,
     * default_points?, option_count?, numeric?, position?} -> {data:
     * section}. option_count, numeric and position are structural (409
     * exam_structure_locked once printed); the type never changes.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $section = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $section->assignment);

        $section = $this->editor->updateSection($section, $request->only(ExamEditor::SECTION_FIELDS));

        return response()->json(['data' => ExamPayload::section($section)]);
    }

    /** DELETE /api/v1/exam-sections/{id} -> 204 with its questions; 409 exam_structure_locked / question_has_responses */
    public function destroy(Request $request, int $id): Response
    {
        $section = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $section->assignment);

        $this->editor->deleteSection($section);

        return response()->noContent();
    }

    /**
     * POST /api/v1/exam-sections/{id}/questions {prompt_text?, options?:
     * [{text}], max_points?, answer_key?, lock_options?, position?,
     * skill_ids?} -> 201 {data: question} (ExamPayload::question).
     * position: the place within the section (default: last).
     */
    public function storeQuestion(Request $request, int $id): JsonResponse
    {
        $section = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $section->assignment);

        $question = $this->editor->createQuestion($section, $request->only(ExamEditor::QUESTION_FIELDS));

        return response()->json(['data' => ExamPayload::question($question)], 201);
    }

    /**
     * @return Builder<ExamSection>
     */
    public static function ownQuery(Request $request): Builder
    {
        return ExamSection::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', ExamController::ownQuery($request)->select('assignments.id'));
    }
}
