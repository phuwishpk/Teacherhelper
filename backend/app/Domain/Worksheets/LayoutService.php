<?php

namespace App\Domain\Worksheets;

use App\Domain\Assignments\AssignmentLocked;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Layout;
use App\Models\Question;

/**
 * POST /assignments/{id}/layout (DESIGN §9.3): builds the layout from the
 * current questions and makes the assignment `ready`.
 *
 * A new layout_version is created only when the pages differ from the current
 * version; rebuilding an unchanged assignment (for example after re-approving
 * a rubric) reuses the current version, so sheets already printed stay valid
 * and versions do not pile up.
 */
class LayoutService
{
    public function __construct(private readonly LayoutBuilder $builder) {}

    /**
     * @return array{layout: Layout, created: bool}
     */
    public function build(Assignment $assignment): array
    {
        return AssignmentLocked::run($assignment->id, function (Assignment $assignment) {
            $questions = $assignment->questions()->get();
            if ($questions->isEmpty()) {
                throw new ApiException('ยังไม่มีคำถาม เพิ่มคำถามก่อนสร้าง layout', 'assignment_empty', 422);
            }

            $pending = $questions->reject(fn (Question $q) => $q->rubricApproved())->pluck('position')->values();
            if ($pending->isNotEmpty()) {
                throw new ApiException(
                    'ต้องอนุมัติ rubric ของข้อ '.$pending->implode(', ').' ก่อนสร้าง layout',
                    'rubric_not_approved',
                    422,
                    ['questions' => $pending->map(fn ($p) => "ข้อ {$p} ยังไม่ได้อนุมัติ rubric")->all()],
                );
            }

            $assignment->setRelation('questions', $questions);
            $markers = ArucoMarkers::load();
            try {
                $plan = $this->builder->plan($assignment);
            } catch (WorksheetLayoutException $e) {
                throw new ApiException($e->getMessage(), 'question_too_tall', 422);
            }

            $current = $assignment->currentLayout();
            if ($current !== null && $plan->toLayoutPages($assignment->id, $current->version, $markers) == $current->pages) {
                $layout = $current;
                $created = false;
            } else {
                $version = (int) $assignment->layouts()->max('version') + 1;
                $layout = $assignment->layouts()->create([
                    'version' => $version,
                    'pages' => $plan->toLayoutPages($assignment->id, $version, $markers),
                ]);
                $created = true;
            }

            $assignment->update([
                'current_layout_version' => $layout->version,
                'status' => Assignment::STATUS_READY,
            ]);

            return ['layout' => $layout, 'created' => $created];
        });
    }
}
