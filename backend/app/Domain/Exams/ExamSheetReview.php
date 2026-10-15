<?php

namespace App\Domain\Exams;

use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\ExamSheetRead;
use App\Models\ExamVersion;
use App\Models\Question;
use App\Models\Response;
use App\Models\Scan;
use App\Models\ScoreEvent;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The teacher's review of scanned answer sheets (DESIGN §22.11), no Gemini:
 *
 * - chooseVersion(): POST /exam-sheets/{scan_id}/version {version_no}. The
 *   teacher looked at the page and picks its version (the bubble was blank
 *   or double, or page 2 came without page 1). Stored as version_source
 *   `teacher`, then the submission is scored at once (a page 2 waiting for
 *   page 1's version is scored with it). A page whose bubble was read may be
 *   corrected the same way. A page waiting for confirm-replace only keeps
 *   the choice until it is confirmed. A later page must match page 1's
 *   known version (422 errors.version_no); page 1 decides for the sheet.
 * - resolve(): POST /exam-responses/{id}/resolve {options[] | value}. The
 *   answer the teacher sees the student meant, for a double, unclear or
 *   unreadable mark. `options` are the positions ON THE SHEET of the
 *   student's version (1 = the first bubble of the row, what the teacher
 *   sees on the page image), stored in exam_answer.resolved as original
 *   positions; `value` is a number (canonical form), empty = no answer.
 *   Scored by code (ExamAnswerScore) with ai_score = final_score, so it is
 *   not a teacher override (ClassRegrade::overridden()) and "ตรวจใหม่ทั้ง
 *   ห้อง" scores the resolved answer against a new key. Reviewed by the
 *   teacher; a score_events `override` with reason "ครูอ่านรอยฝน" is kept
 *   as history only. Resolving again replaces the previous reading.
 *
 * Published submissions are frozen like PATCH /responses (409
 * submission_published): a rescan and confirm-replace reopens them.
 */
final class ExamSheetReview
{
    public const RESOLVE_REASON = 'ครูอ่านรอยฝน';

    public function __construct(private readonly ExamSheetGrader $grader) {}

    /**
     * @return array<string, mixed> the page body of POST /exam-sheets (ExamSheetIngestor::body)
     *
     * @throws ApiException 409 scan_superseded / submission_published
     * @throws ValidationException version_no outside 1..version_count
     */
    public function chooseVersion(User $teacher, Scan $scan, mixed $input): array
    {
        $exam = Assignment::query()->findOrFail($scan->submission->assignment_id);
        $count = max(1, (int) $exam->version_count);
        $data = Validator::make(is_array($input) ? $input : [], [
            'version_no' => ['required', 'integer', 'min:1', 'max:'.$count],
        ], [
            'version_no.required' => 'เลือกชุดข้อสอบ (version_no)',
            'version_no.integer' => 'ชุดข้อสอบต้องเป็นตัวเลข',
            'version_no.min' => "ข้อสอบนี้มีชุด 1–{$count} เท่านั้น",
            'version_no.max' => "ข้อสอบนี้มีชุด 1–{$count} เท่านั้น",
        ])->validate();
        $versionNo = (int) $data['version_no'];

        return DB::transaction(function () use ($teacher, $scan, $exam, $versionNo) {
            $submission = Submission::query()->lockForUpdate()->findOrFail($scan->submission_id);
            $scan = Scan::query()->lockForUpdate()->findOrFail($scan->id);
            if ($scan->isSuperseded()) {
                throw new ApiException('มีการสแกนหน้านี้ใหม่กว่านี้แล้ว เลือกชุดที่สแกนล่าสุดแทน', 'scan_superseded', 409);
            }
            if ($scan->isActive() && $submission->isPublished()) {
                throw self::published();
            }
            if ($scan->page_no > 1) {
                self::assertSameAsPageOne($scan, $versionNo);
            }
            $read = ExamSheetRead::query()->lockForUpdate()->findOrFail($scan->id);
            $read->forceFill(['version_no' => $versionNo, 'version_source' => ExamSheetRead::SOURCE_TEACHER])->save();

            if ($scan->isActive()) {
                $this->grader->apply($submission, $exam, ExamScanKit::keys($exam), $teacher);
                ExamSheetIngestor::refreshStatus($submission, $exam);
            }

            return ExamSheetIngestor::body($exam, $scan);
        });
    }

    /**
     * @throws ApiException 409 submission_published, 422 validation_failed
     */
    public function resolve(User $teacher, Response $response, mixed $input): Response
    {
        $question = Question::query()->with('section')->findOrFail($response->question_id);
        $answer = self::validated($question, $response, is_array($input) ? $input : []);

        return DB::transaction(function () use ($teacher, $response, $question, $answer) {
            $submission = Submission::query()->lockForUpdate()->findOrFail($response->submission_id);
            if ($submission->isPublished()) {
                throw self::published();
            }
            $response = Response::query()->lockForUpdate()->findOrFail($response->id);
            $examAnswer = (array) $response->exam_answer;
            $order = self::optionOrder($submission->assignment_id, (int) ($examAnswer['version_no'] ?? 0), $question->id);
            $examAnswer['resolved'] = [
                'selected' => $question->type === Question::TYPE_NUMERIC ? [] : ExamScanKit::original($answer['options'], $order),
                'value' => $answer['value'],
                'by' => $teacher->id,
                'at' => now()->utc()->toIso8601ZuluString(),
            ];
            $scored = ExamAnswerScore::of($question, $examAnswer);
            $old = [$response->effectiveScore(), $response->effectiveUnderstanding()];
            $errors = $scored['blank'] ? ['no_answer'] : [];

            $response->forceFill([
                'exam_answer' => $examAnswer,
                'ai_score' => $scored['score'],
                'ai_understanding' => $scored['understanding'],
                'ai_error_types' => $errors,
                'final_score' => $scored['score'],
                'final_understanding' => $scored['understanding'],
                'final_error_types' => $errors,
                'reviewed_by' => $teacher->id,
                'reviewed_at' => now(),
            ])->save();

            ScoreEvent::create([
                'response_id' => $response->id,
                'actor' => ScoreEvent::ACTOR_TEACHER,
                'actor_user_id' => $teacher->id,
                'action' => ScoreEvent::ACTION_OVERRIDE,
                'old_score' => $old[0],
                'new_score' => $scored['score'],
                'old_understanding' => $old[1],
                'new_understanding' => $scored['understanding'],
                'reason' => self::RESOLVE_REASON,
            ]);

            ExamSheetIngestor::refreshStatus($submission, Assignment::query()->findOrFail($submission->assignment_id));

            return $response;
        });
    }

    /**
     * Original position by displayed position of a question in a version,
     * null when that version does not shuffle its options.
     *
     * @return list<int>|null
     */
    public static function optionOrder(int $assignmentId, int $versionNo, int $questionId): ?array
    {
        $orders = ExamVersion::query()->where('assignment_id', $assignmentId)->where('version_no', $versionNo)->value('option_orders');
        if (is_string($orders)) {
            $orders = json_decode($orders, true);
        }
        $order = is_array($orders) ? ($orders[$questionId] ?? $orders[(string) $questionId] ?? null) : null;

        return is_array($order) ? array_values(array_map('intval', $order)) : null;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{options: list<int>, value: string|null}
     *
     * @throws ValidationException
     */
    private static function validated(Question $question, Response $response, array $input): array
    {
        if (! is_array($response->exam_answer)) {
            throw ValidationException::withMessages(['response' => ['ข้อนี้ไม่ได้มาจากกระดาษคำตอบ']]);
        }
        if ($question->type === Question::TYPE_NUMERIC) {
            $data = Validator::make($input, [
                'value' => ['present', 'nullable', 'string', 'max:16'],
                'options' => ['prohibited'],
            ], [
                'value.present' => 'ใส่ตัวเลขที่นักเรียนตั้งใจตอบ (ว่าง = ไม่ได้ตอบ)',
                'value.string' => 'ตัวเลขต้องส่งเป็นข้อความ',
                'value.max' => 'ตัวเลขยาวเกินไป',
                'options.prohibited' => 'ข้อเติมตัวเลขไม่มีตัวเลือก ส่ง value แทน',
            ])->validate();
            $text = trim((string) ($data['value'] ?? ''));
            if ($text === '') {
                return ['options' => [], 'value' => null];
            }
            $value = NumericAnswer::canonical($text);
            if ($value === null) {
                throw ValidationException::withMessages(['value' => ['ตัวเลขไม่ถูกต้อง']]);
            }

            return ['options' => [], 'value' => $value];
        }

        $count = $question->section?->choiceCount() ?? 0;
        $data = Validator::make($input, [
            'options' => ['present', 'array', 'max:'.max(1, $count)],
            'options.*' => ['integer', 'distinct', 'min:1', 'max:'.max(1, $count)],
            'value' => ['prohibited'],
        ], [
            'options.present' => 'เลือกตัวเลือกที่นักเรียนตั้งใจฝน (ว่าง = ไม่ได้ตอบ)',
            'options.array' => 'options ต้องเป็นรายการ',
            'options.max' => "ข้อนี้มี {$count} ตัวเลือก",
            'options.*.integer' => 'ตัวเลือกต้องเป็นตำแหน่งบนกระดาษ (1–'.$count.')',
            'options.*.distinct' => 'ตัวเลือกซ้ำกัน',
            'options.*.min' => 'ตัวเลือกต้องเป็นตำแหน่งบนกระดาษ (1–'.$count.')',
            'options.*.max' => 'ตัวเลือกต้องเป็นตำแหน่งบนกระดาษ (1–'.$count.')',
            'value.prohibited' => 'ข้อนี้เป็นข้อฝนตัวเลือก ส่ง options แทน',
        ])->validate();
        $options = array_values(array_map('intval', (array) $data['options']));
        sort($options);

        return ['options' => $options, 'value' => null];
    }

    /**
     * Every page of one sheet has the version of page 1: a later page may
     * only be given another version while page 1's is not known.
     *
     * @throws ValidationException
     */
    private static function assertSameAsPageOne(Scan $scan, int $versionNo): void
    {
        $pageOne = ExamSheetRead::query()
            ->whereIn('scan_id', Scan::query()->select('id')
                ->where('submission_id', $scan->submission_id)
                ->where('page_no', 1)
                ->where('state', Scan::STATE_ACTIVE))
            ->value('version_no');
        if ($pageOne !== null && (int) $pageOne !== $versionNo) {
            $label = ExamVersions::label((int) $pageOne);
            throw ValidationException::withMessages(['version_no' => ["หน้า 1 ของนักเรียนคนนี้เป็นชุด {$label} ทุกหน้าต้องเป็นชุดเดียวกัน ถ้าชุดผิด ให้เลือกชุดที่หน้า 1"]]);
        }
    }

    private static function published(): ApiException
    {
        return new ApiException('ประกาศผลของนักเรียนคนนี้แล้ว แก้ได้ผ่านคำขอตรวจใหม่หรือการสแกนใหม่เท่านั้น', 'submission_published', 409);
    }
}
