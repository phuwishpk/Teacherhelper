<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\QuestionData;
use App\Domain\Assignments\QuestionEditor;
use App\Domain\Exams\ExamEditor;
use App\Domain\Exams\ExamGuard;
use App\Domain\Exams\ExamPayload;
use App\Http\Controllers\Controller;
use App\Http\Resources\QuestionResource;
use App\Models\Question;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Questions of an assignment (DESIGN §9.3). Validation of every field,
 * including the answer_key shape per type, lives in QuestionData.
 */
class QuestionController extends Controller
{
    public function __construct(
        private readonly QuestionEditor $editor,
        private readonly ExamEditor $exams,
    ) {}

    /** POST /api/v1/assignments/{id}/questions -> 201 {data: question} */
    public function store(Request $request, int $id): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $assignment);
        // Exam questions are added through POST /exam-sections/{id}/questions.
        ExamGuard::homeworkOnly($assignment);

        $question = $this->editor->create($assignment, $request->only(QuestionData::FIELDS));

        return (new QuestionResource(self::loadDetail($question)))->response()->setStatusCode(201);
    }

    /**
     * PATCH /api/v1/questions/{id} -> {data: question}; absent fields keep
     * their value. An exam question takes the exam fields (DESIGN §22.15:
     * prompt_text, options, max_points, answer_key in the exam shape,
     * lock_options, approve, position within its section, skill_ids) and
     * answers with the exam question payload.
     */
    public function update(Request $request, int $id): QuestionResource|JsonResponse
    {
        $question = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $question);
        if ($question->isExamQuestion()) {
            $question = $this->exams->updateQuestion($question, $request->only(ExamEditor::QUESTION_FIELDS));

            return response()->json(['data' => ExamPayload::question($question)]);
        }

        $question = $this->editor->update($question, $request->only(QuestionData::FIELDS));

        return new QuestionResource(self::loadDetail($question));
    }

    /** DELETE /api/v1/questions/{id} -> 204 */
    public function destroy(Request $request, int $id): Response
    {
        $question = self::ownQuery($request)->findOrFail($id);
        Gate::authorize('delete', $question);

        if ($question->isExamQuestion()) {
            $this->exams->deleteQuestion($question);
        } else {
            $this->editor->delete($question);
        }

        return response()->noContent();
    }

    /**
     * Questions of the teacher's own assignments.
     *
     * @return Builder<Question>
     */
    public static function ownQuery(Request $request): Builder
    {
        return Question::query()
            ->with('assignment.classroom')
            ->whereIn('assignment_id', AssignmentController::ownQuery($request)->select('assignments.id'));
    }

    public static function loadDetail(Question $question): Question
    {
        return $question->refresh()->load(['skills', 'rubricCriteria']);
    }
}
