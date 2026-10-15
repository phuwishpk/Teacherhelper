<?php

namespace App\Domain\Exams;

use App\Models\Layout;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;

/**
 * How an exam answer is shown (DESIGN §22.11, §22.12).
 *
 * teacher(): the `exam` block of GET /responses/{id} (and of resolve), for
 * the review screen: the warped page with the row highlighted and the
 * bubbles in the order the student's version printed them.
 *
 *   {sheet_no, version_no, version_label, labels[] (by position on the
 *    sheet), selected[] (positions on the sheet), value, doubts[],
 *    resolved: {selected[], value, by, at} | null, scan_id, page_no,
 *    page_image_url, rect (normalized, from the layout region) | null}
 *
 * queue(): the short `exam_answer` of a review-queue row.
 */
final class ExamAnswerView
{
    /**
     * @return array<string, mixed>|null
     */
    public static function teacher(Response $response): ?array
    {
        $answer = $response->exam_answer;
        $question = $response->question;
        if (! is_array($answer) || ! $question instanceof Question) {
            return null;
        }
        $versionNo = (int) ($answer['version_no'] ?? 1);
        $sheetNo = (int) ($answer['sheet_no'] ?? 0);
        $order = ExamSheetReview::optionOrder((int) $question->assignment_id, $versionNo, (int) $question->id);
        $resolved = is_array($answer['resolved'] ?? null) ? $answer['resolved'] : null;
        $scan = $response->scan_id === null ? null : Scan::query()->find($response->scan_id);

        return [
            'sheet_no' => $sheetNo,
            'version_no' => $versionNo,
            'version_label' => ExamVersions::label($versionNo),
            'labels' => ExamAnswerScore::labels($question),
            'selected' => ExamVersions::displayed((array) ($answer['selected'] ?? []), $order),
            'value' => $answer['value'] ?? null,
            'doubts' => array_values((array) ($answer['doubts'] ?? [])),
            'resolved' => $resolved === null ? null : [
                'selected' => ExamVersions::displayed((array) ($resolved['selected'] ?? []), $order),
                'value' => $resolved['value'] ?? null,
                'by' => $resolved['by'] ?? null,
                'at' => $resolved['at'] ?? null,
            ],
            'scan_id' => $scan?->id,
            'page_no' => $scan?->page_no,
            'page_image_url' => $scan === null ? null : route('api.scans.page', $scan->id, false),
            'rect' => $scan === null ? null : self::rect($question->assignment_id, $scan, $sheetNo),
        ];
    }

    /**
     * @return array{sheet_no: int, version_no: int|null, doubts: list<string>, resolved: bool}|null
     */
    public static function queue(Response $response): ?array
    {
        $answer = $response->exam_answer;
        if (! is_array($answer)) {
            return null;
        }

        return [
            'sheet_no' => (int) ($answer['sheet_no'] ?? 0),
            'version_no' => isset($answer['version_no']) ? (int) $answer['version_no'] : null,
            'doubts' => array_values((array) ($answer['doubts'] ?? [])),
            'resolved' => is_array($answer['resolved'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function rect(int $assignmentId, Scan $scan, int $sheetNo): ?array
    {
        $layout = Layout::query()->where('assignment_id', $assignmentId)->where('version', $scan->layout_version)->first();
        foreach ((array) ($layout?->pages ?? []) as $index => $page) {
            if ((int) ($page['page'] ?? $index + 1) !== $scan->page_no) {
                continue;
            }
            foreach ((array) ($page['regions'] ?? []) as $region) {
                if ((int) ($region['sheet_no'] ?? 0) === $sheetNo && is_array($region['rect'] ?? null)) {
                    return $region['rect'];
                }
            }
        }

        return null;
    }
}
