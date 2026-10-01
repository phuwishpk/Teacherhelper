<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Exams\ExamEditor;
use App\Domain\Exams\ExamFigures;
use App\Domain\Exams\ExamImages;
use App\Domain\Exams\ExamPayload;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Question;
use App\Models\QuestionOption;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Images of exam questions and mcq options (DESIGN §22.2, §22.15): upload
 * (multipart `image`, JPEG/PNG/WebP up to 5 MB, scaled by GD, stored as
 * JPEG), remove, and stream to the owner. Only questions of the teacher's
 * own exams; anything else is a 404. An image is not structural, so it may
 * change after printing. A teacher's own image clears figure_source.
 */
class ExamImageController extends Controller
{
    /** POST /api/v1/questions/{id}/image -> {data: question} */
    public function storeQuestion(Request $request, int $id): JsonResponse
    {
        $question = self::ownQuestions($request)->findOrFail($id);
        Gate::authorize('update', $question);

        $question = AssignmentLocked::run($question->assignment_id, function (Assignment $exam) use ($request, $question) {
            $question = Question::query()->findOrFail($question->id);
            $path = ExamImages::questionPath($question, $exam);
            ExamImages::store($request->file(ExamImages::FIELD), $path);
            $question->forceFill(['prompt_image_path' => $path, 'figure_source' => null])->save();
            // A blank question of a manual exam now has a prompt (DESIGN §22.4).
            ExamEditor::autoApprove($question, $exam);

            return $question;
        });

        return response()->json(['data' => ExamPayload::question($question->refresh())]);
    }

    /** DELETE /api/v1/questions/{id}/image -> {data: question} */
    public function destroyQuestion(Request $request, int $id): JsonResponse
    {
        $question = self::ownQuestions($request)->findOrFail($id);
        Gate::authorize('update', $question);

        $question = AssignmentLocked::run($question->assignment_id, function () use ($question) {
            $question = Question::query()->findOrFail($question->id);
            ExamImages::delete($question->prompt_image_path);
            $question->forceFill(['prompt_image_path' => null, 'figure_source' => null])->save();

            return $question;
        });

        return response()->json(['data' => ExamPayload::question($question->refresh())]);
    }

    /** GET /api/v1/questions/{id}/image -> image/jpeg; 404 without an image */
    public function showQuestion(Request $request, int $id): StreamedResponse
    {
        $question = self::ownQuestions($request)->findOrFail($id);
        Gate::authorize('update', $question);

        return self::stream($question->prompt_image_path);
    }

    /** POST /api/v1/question-options/{id}/image -> {data: question} */
    public function storeOption(Request $request, int $id): JsonResponse
    {
        $option = self::ownOptions($request)->findOrFail($id);
        Gate::authorize('update', $option->question);

        AssignmentLocked::run($option->question->assignment_id, function (Assignment $exam) use ($request, $option) {
            $option = QuestionOption::query()->findOrFail($option->id);
            $path = ExamImages::optionPath($option, $exam);
            ExamImages::store($request->file(ExamImages::FIELD), $path);
            $option->forceFill(['image_path' => $path, 'figure_source' => null])->save();
        });

        return response()->json(['data' => ExamPayload::question(Question::query()->findOrFail($option->question_id))]);
    }

    /** DELETE /api/v1/question-options/{id}/image -> {data: question} */
    public function destroyOption(Request $request, int $id): JsonResponse
    {
        $option = self::ownOptions($request)->findOrFail($id);
        Gate::authorize('update', $option->question);

        AssignmentLocked::run($option->question->assignment_id, function () use ($option) {
            $option = QuestionOption::query()->findOrFail($option->id);
            ExamImages::delete($option->image_path);
            $option->forceFill(['image_path' => null, 'figure_source' => null])->save();
        });

        return response()->json(['data' => ExamPayload::question(Question::query()->findOrFail($option->question_id))]);
    }

    /** GET /api/v1/question-options/{id}/image -> image/jpeg; 404 without an image */
    public function showOption(Request $request, int $id): StreamedResponse
    {
        $option = self::ownOptions($request)->findOrFail($id);
        Gate::authorize('update', $option->question);

        return self::stream($option->image_path);
    }

    /**
     * PUT /api/v1/questions/{id}/figure {page_image_id, box_2d: [ymin, xmin,
     * ymax, xmax] (0–1000)} -> {data: question}: the teacher boxes the figure
     * again on a page image of the same exam; the server crops it with GD
     * (DESIGN §22.4). 422 errors.page_image_id / errors.box_2d,
     * document_missing once the page image was deleted.
     */
    public function figureQuestion(Request $request, int $id): JsonResponse
    {
        $question = self::ownQuestions($request)->findOrFail($id);
        Gate::authorize('update', $question);

        ExamFigures::recrop($question, $request->only(['page_image_id', 'box_2d']));

        return response()->json(['data' => ExamPayload::question($question->refresh())]);
    }

    /** PUT /api/v1/question-options/{id}/figure (as figureQuestion) -> {data: question} */
    public function figureOption(Request $request, int $id): JsonResponse
    {
        $option = self::ownOptions($request)->findOrFail($id);
        Gate::authorize('update', $option->question);

        ExamFigures::recrop($option, $request->only(['page_image_id', 'box_2d']));

        return response()->json(['data' => ExamPayload::question(Question::query()->findOrFail($option->question_id))]);
    }

    private static function stream(?string $path): StreamedResponse
    {
        abort_if($path === null || ! ExamImages::disk()->exists($path), 404);

        return ExamImages::disk()->response($path, null, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    /**
     * Questions of the teacher's own exams.
     *
     * @return Builder<Question>
     */
    private static function ownQuestions(Request $request): Builder
    {
        return Question::query()->with('assignment.classroom')->whereIn('assignment_id', ExamController::ownQuery($request)->select('assignments.id'));
    }

    /**
     * @return Builder<QuestionOption>
     */
    private static function ownOptions(Request $request): Builder
    {
        return QuestionOption::query()->with('question.assignment.classroom')->whereIn('question_id', self::ownQuestions($request)->select('questions.id'));
    }
}
