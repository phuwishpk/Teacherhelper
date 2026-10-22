<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\User;

/**
 * What the phone needs to read and score answer sheets offline (DESIGN
 * §22.9 step 1, GET /exams/{id}/scan-kit):
 *
 *   {assignment_id, title, layout_version, page_count, version_count,
 *    sheet_identity, student_code_digits,
 *    kit_hash, versions: [{version_no, label, key: [{sheet_no, question_id,
 *    type, points, accepted_options | accepted_values}]}], layouts: [page],
 *    roster: [{student_id, student_number, name, student_code}]}
 *
 * sheet_identity `code` (§22.19): the sheets carry no student, the phone
 * reads the student ID from the grid and finds it in the roster's
 * student_code (null for a student without one).
 *
 * Keys are per version by the number on the sheet, mcq options as the
 * displayed positions of that version (ExamVersions::keyOf). layouts are the
 * pages of the current answer-sheet layout; before the first answer-sheet or
 * key-sheet print there is none (layout_version null, layouts []).
 * kit_hash changes whenever anything above does, so the app can tell that
 * its cached kit is stale (a key edited after the kit was downloaded).
 *
 * The server uses the same keys (with each item's option_order) to score
 * uploaded sheets and to turn displayed positions back into original ones.
 */
final class ExamScanKit
{
    /**
     * @throws ApiException 422 exam_manual_grading, 409 answer_key_not_approved
     */
    public static function assertScannable(Assignment $exam): void
    {
        if ($exam->isManualExam()) {
            throw new ApiException('ข้อสอบที่ครูตรวจเองไม่มีกระดาษคำตอบให้สแกน', 'exam_manual_grading', 422);
        }
        if (! $exam->keyApproved()) {
            throw new ApiException('ต้องอนุมัติเฉลยก่อนเตรียมสแกน', 'answer_key_not_approved', 409);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function payload(Assignment $exam): array
    {
        $layout = $exam->currentLayout();
        $versions = [];
        foreach (self::keys($exam) as $versionNo => $items) {
            $versions[] = [
                'version_no' => $versionNo,
                'label' => ExamVersions::label($versionNo),
                'key' => array_map(fn (array $item) => self::publicItem($item), array_values($items)),
            ];
        }
        $roster = $exam->classroom()->firstOrFail()->students()->get()->map(fn (User $s) => [
            'student_id' => (int) $s->id,
            'student_number' => (int) $s->pivot->student_number,
            'name' => (string) $s->name,
            'student_code' => $s->student_code,
        ])->values()->all();

        $kit = [
            'assignment_id' => (int) $exam->id,
            'title' => (string) $exam->title,
            'layout_version' => $layout?->version,
            'page_count' => $layout?->pageCount() ?? 0,
            'version_count' => (int) $exam->version_count,
            'sheet_identity' => (string) $exam->sheet_identity,
            'student_code_digits' => $exam->student_code_digits,
            'versions' => $versions,
            'layouts' => $layout === null ? [] : array_values($layout->pages),
            'roster' => $roster,
        ];

        return ['kit_hash' => hash('sha256', json_encode($kit, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)), ...$kit];
    }

    /**
     * Keys of every stored version: version_no => sheet_no => {sheet_no,
     * question_id, type, points, accepted_options | accepted_values,
     * option_order}.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    public static function keys(Assignment $exam): array
    {
        $questions = Question::query()->where('assignment_id', $exam->id)->with('section')->get()->keyBy('id');
        $out = [];
        foreach (ExamVersions::stored($exam) as $version) {
            /** @var ExamVersion $version */
            $items = [];
            foreach (ExamVersions::keyOf($version, $questions) as $item) {
                $items[(int) $item['sheet_no']] = $item;
            }
            $out[(int) $version->version_no] = $items;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private static function publicItem(array $item): array
    {
        $out = [
            'sheet_no' => $item['sheet_no'],
            'question_id' => $item['question_id'],
            'type' => $item['type'],
            'points' => $item['points'],
        ];
        if ($item['type'] === Question::TYPE_NUMERIC) {
            $out['accepted_values'] = $item['accepted_values'];
        } else {
            $out['accepted_options'] = $item['accepted_options'];
        }

        return $out;
    }

    /**
     * Displayed positions of a version -> original positions (DESIGN §22.5).
     *
     * @param  list<int>  $displayed
     * @param  list<int>|null  $order  original position by displayed position, null = not shuffled
     * @return list<int>
     */
    public static function original(array $displayed, ?array $order): array
    {
        $out = [];
        foreach ($displayed as $position) {
            $out[] = $order === null ? $position : (int) ($order[$position - 1] ?? $position);
        }
        sort($out);

        return array_values(array_unique($out));
    }
}
