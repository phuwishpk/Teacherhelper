<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Question;
use Illuminate\Support\Facades\Validator;

/**
 * POST /exams/{id}/key-sheet-read (DESIGN §22.3 "สแกนกระดาษเฉลย"): the
 * teacher bubbled the key of one version on the key sheet (QR student 0);
 * the phone sends what it read, {qr, version_no?, version_fill?, rows,
 * digits?}, and gets a PROPOSAL for the master key. Nothing is saved: the
 * app fills its key grid and the teacher saves with PUT /exams/{id}/answer-key.
 *
 *   {data: {version_no, page, proposal: [{question_id, sheet_no, type,
 *           accepted_options | accepted_values, doubtful, differs}]}}
 *
 * - The QR must be this exam's key sheet (422 qr_invalid) from the current
 *   layout (422 layout_unknown: print a new key sheet after unlocking).
 * - The version comes from the version bubbles of page 1; page 2 carries
 *   none, so the app sends the version_no page 1 gave (422 version_unknown
 *   when neither decides it).
 * - Options go back to their ORIGINAL positions through the version's
 *   permutation. On the key sheet several marked options of an mcq mean
 *   several accepted answers; an unclear bubble, a blank row, two marks on
 *   true/false or an unreadable number make the row `doubtful` (a number
 *   is one value; more accepted values are typed in the grid). `differs`
 *   compares with the saved master key.
 */
final class ExamKeySheetReader
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{version_no: int, page: int, proposal: list<array<string, mixed>>}
     */
    public static function read(Assignment $exam, array $input): array
    {
        if ($exam->isManualExam()) {
            throw new ApiException('ข้อสอบที่ครูตรวจเองไม่มีกระดาษเฉลย', 'exam_manual_grading', 422);
        }
        $data = Validator::make($input, [
            'qr' => ['required', 'string', 'max:128'],
            'version_no' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.ExamVersions::maxVersions()],
            ...ExamSheetReading::rules(),
        ], [
            'qr.required' => 'ไม่มีข้อความ QR ของกระดาษเฉลย',
            'integer' => ':attribute ต้องเป็นจำนวนเต็ม',
            'min' => ':attribute ต้องไม่น้อยกว่า :min',
            ...ExamSheetReading::messages(),
        ])->validate();

        $qr = ExamSheetIngestor::verifyQr((string) $data['qr']);
        if ($qr->assignmentId !== $exam->id || ! $qr->isAnonymous()) {
            throw new ApiException('QR นี้ไม่ใช่กระดาษเฉลยของข้อสอบนี้', 'qr_invalid', 422);
        }
        $layout = $exam->currentLayout();
        if ($layout === null || $layout->version !== $qr->layoutVersion) {
            throw new ApiException('กระดาษเฉลยนี้พิมพ์ก่อนปลดล็อกโครงสร้าง พิมพ์กระดาษเฉลยใหม่', 'layout_unknown', 422);
        }
        $page = null;
        foreach ($layout->pages as $index => $candidate) {
            if ((int) ($candidate['page'] ?? $index + 1) === $qr->page) {
                $page = $candidate;
            }
        }
        if ($page === null) {
            throw new ApiException("กระดาษเฉลยนี้มี {$layout->pageCount()} หน้า ไม่มีหน้า {$qr->page}", 'page_mismatch', 422);
        }
        $reading = ExamSheetReading::against($data['version_fill'] ?? null, $data['rows'], $data['digits'] ?? null, $page);

        $keys = ExamScanKit::keys($exam);
        $asked = isset($data['version_no']) ? (int) $data['version_no'] : null;
        $version = ExamSheetScorer::version((int) $exam->version_count, $qr->page, $reading->versionFill, $asked)['version_no'];
        if ($version === null && $qr->page === 1 && $asked !== null) {
            $version = $asked;
        }
        if ($version === null || ! isset($keys[$version])) {
            throw new ApiException('อ่านชุดของกระดาษเฉลยไม่ได้ ฝนวงชุดให้ชัดเพียงวงเดียวแล้วสแกนใหม่', 'version_unknown', 422);
        }

        $questions = Question::query()->where('assignment_id', $exam->id)->get()->keyBy('id');
        $proposal = [];
        foreach ($keys[$version] as $sheetNo => $item) {
            $isRow = array_key_exists((string) $sheetNo, $reading->rows);
            $isBlock = array_key_exists((string) $sheetNo, $reading->digits);
            if (! $isRow && ! $isBlock) {
                continue; // on the other page
            }
            /** @var Question|null $question */
            $question = $questions->get($item['question_id']);
            $saved = is_array($question?->answer_key) ? $question->answer_key : [];
            $entry = ['question_id' => $item['question_id'], 'sheet_no' => $sheetNo, 'type' => $item['type']];
            if ($isBlock) {
                $read = ExamSheetScorer::readDigits($reading->digits[(string) $sheetNo]);
                $values = $read['value'] === null ? [] : [$read['value']];
                $entry['accepted_values'] = $values;
                $entry['doubtful'] = $values === [] || $read['doubts'] !== [];
                $current = array_values(array_map('strval', (array) ($saved['accepted_values'] ?? [])));
                $entry['differs'] = $values !== $current;
            } else {
                $read = ExamSheetScorer::readRow($reading->rows[(string) $sheetNo]);
                $options = ExamScanKit::original($read['selected'], $item['option_order'] ?? null);
                $single = $item['type'] === Question::TYPE_TRUE_FALSE;
                $entry['accepted_options'] = $single && count($options) > 1 ? [] : $options;
                $entry['doubtful'] = $options === []
                    || in_array(ExamSheetScorer::AMBIGUOUS_MARK, $read['doubts'], true)
                    || ($single && count($options) > 1);
                $current = array_map('intval', (array) ($saved['accepted_options'] ?? []));
                sort($current);
                $entry['differs'] = $entry['accepted_options'] !== array_values($current);
            }
            $proposal[] = $entry;
        }
        usort($proposal, fn (array $a, array $b) => $a['sheet_no'] <=> $b['sheet_no']);

        return ['version_no' => $version, 'page' => $qr->page, 'proposal' => $proposal];
    }
}
