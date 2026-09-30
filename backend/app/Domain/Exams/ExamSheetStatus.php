<?php

namespace App\Domain\Exams;

use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;

/**
 * GET /exams/{id}/sheet-status (DESIGN §22.10 "แถบสรุป", §22.15): which
 * students' answer sheets the server has, for the running summary of the
 * scan screen.
 *
 *   {data: [{student_id, student_number, name, pages_received[], page_count,
 *            version_no, score, status, doubt_count, needs_version}],
 *    summary: {scanned, total, missing_numbers[], page_count, max_score}}
 *
 * pages_received are the active pages; a student counts as scanned when
 * every page of the current layout is in. score is the sum of the current
 * answer scores (null before any is written); status is the submission's,
 * or `missing` without one. doubt_count counts answers still waiting in the
 * review queue.
 */
final class ExamSheetStatus
{
    /**
     * @return array{data: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function of(Assignment $exam): array
    {
        $pageCount = $exam->currentLayout()?->pageCount() ?? 0;
        $submissions = Submission::query()->where('assignment_id', $exam->id)->get()->keyBy('student_id');
        $submissionIds = $submissions->pluck('id');

        $scans = Scan::query()
            ->whereIn('submission_id', $submissionIds)
            ->where('state', Scan::STATE_ACTIVE)
            ->get(['id', 'submission_id', 'page_no'])
            ->groupBy('submission_id');
        $reads = ExamSheetRead::query()
            ->whereIn('scan_id', Scan::query()->select('id')->whereIn('submission_id', $submissionIds)->where('state', Scan::STATE_ACTIVE))
            ->get()
            ->keyBy('scan_id');
        $responses = Response::query()->whereIn('submission_id', $submissionIds)->get()->groupBy('submission_id');

        $rows = [];
        $scanned = 0;
        $missing = [];
        /** @var User $student */
        foreach ($exam->classroom()->firstOrFail()->students()->get() as $student) {
            $number = (int) $student->pivot->student_number;
            $submission = $submissions->get($student->id);
            $pages = [];
            $versionNo = null;
            $needsVersion = false;
            $score = null;
            $doubts = 0;
            if ($submission instanceof Submission) {
                foreach ($scans->get($submission->id) ?? [] as $scan) {
                    $pages[] = (int) $scan->page_no;
                    $read = $reads->get($scan->id);
                    if ($read instanceof ExamSheetRead) {
                        if ($read->version_no === null) {
                            $needsVersion = true;
                        } elseif ((int) $scan->page_no === 1 || $versionNo === null) {
                            $versionNo = $read->version_no;
                        }
                    }
                }
                $answers = $responses->get($submission->id) ?? collect();
                if ($answers->isNotEmpty()) {
                    $score = round((float) $answers->sum(fn (Response $r) => (float) $r->effectiveScore()), 2);
                }
                $doubts = $answers->filter(fn (Response $r) => $r->priority_band === 'check' && $r->reviewed_at === null)->count();
            }
            $pages = array_values(array_unique($pages));
            sort($pages);
            $complete = $pageCount > 0 && count(array_intersect(range(1, $pageCount), $pages)) === $pageCount;
            if ($complete) {
                $scanned++;
            } else {
                $missing[] = $number;
            }

            $rows[] = [
                'student_id' => (int) $student->id,
                'student_number' => $number,
                'name' => (string) $student->name,
                'pages_received' => $pages,
                'page_count' => $pageCount,
                'version_no' => $versionNo,
                'score' => $score,
                'status' => $submission?->status ?? 'missing',
                'doubt_count' => $doubts,
                'needs_version' => $needsVersion,
            ];
        }

        return [
            'data' => $rows,
            'summary' => [
                'scanned' => $scanned,
                'total' => count($rows),
                'missing_numbers' => $missing,
                'page_count' => $pageCount,
                'max_score' => round((float) $exam->questions()->sum('max_points'), 2),
            ],
        ];
    }
}
