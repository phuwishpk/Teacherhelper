<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamPayload;
use App\Domain\Exams\ExamQuestionCopier;
use App\Http\Controllers\Controller;
use App\Models\Question;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Copying questions from the teacher's earlier exams (DESIGN §22.4 item 3,
 * §22.15): the library holds the questions of exams this teacher created,
 * never another teacher's.
 */
class ExamQuestionLibraryController extends Controller
{
    private const PER_PAGE = 50;

    /**
     * GET /api/v1/teacher/exam-questions?course_id=&exam_id=&q=&exclude_exam=
     * -> {data: [question + {exam: {id, title, course_id}, section: {id,
     * title, type, option_count, numeric}}], meta: {per_page, next_cursor}}
     */
    public function index(Request $request): JsonResponse
    {
        $page = ExamQuestionCopier::library($request->user(), $request->only(['course_id', 'exam_id', 'q', 'exclude_exam']))
            ->cursorPaginate(self::PER_PAGE);

        return response()->json([
            'data' => array_map(function (Question $question) {
                $section = $question->section;

                return ExamPayload::question($question) + [
                    'exam' => ['id' => $question->assignment->id, 'title' => $question->assignment->title, 'course_id' => $question->assignment->course_id],
                    'section' => [
                        'id' => $section->id,
                        'title' => $section->title,
                        'type' => $section->type,
                        'option_count' => $section->option_count,
                        'numeric' => $section->numeric_digits === null ? null : [
                            'digits' => $section->numeric_digits,
                            'allow_negative' => $section->numeric_allow_negative,
                            'allow_decimal' => $section->numeric_allow_decimal,
                        ],
                    ],
                ];
            }, $page->items()),
            'meta' => [
                'per_page' => self::PER_PAGE,
                'next_cursor' => $page->nextCursor()?->encode(),
            ],
        ]);
    }

    /**
     * POST /api/v1/exams/{id}/copy-questions {question_ids[], section_id?}
     * -> 201 {data: {created, question_ids[], skipped: [{question_id, reason,
     * reason_th}], exam: exam payload}}. A question of another teacher is a
     * 422 (errors.question_ids.N). 409 exam_structure_locked; 422
     * too_many_questions / validation_failed.
     */
    public function copy(Request $request, int $id, ExamQuestionCopier $copier): JsonResponse
    {
        $exam = ExamController::ownQuery($request)->findOrFail($id);
        Gate::authorize('update', $exam);

        $outcome = $copier->copy($exam, $request->user(), $request->only(['question_ids', 'section_id']));

        return response()->json(['data' => $outcome + ['exam' => ExamPayload::of($exam->refresh())]], 201);
    }
}
