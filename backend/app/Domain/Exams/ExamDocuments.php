<?php

namespace App\Domain\Exams;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Documents\CostEstimate;
use App\Domain\Documents\DocumentSelection;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\TeacherGuidance;
use App\Exceptions\ApiException;
use App\Jobs\ReadExamDocumentJob;
use App\Models\Assignment;
use App\Models\DocumentExtraction;
use App\Models\ExamImport;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reading a teacher's exam file once (DESIGN §22.4) with the document path
 * of §19.5: files from POST /documents, more than 30 pages need a page range
 * (422 document_too_long), the result cached for the whole school in
 * document_extractions (purpose exam, keyed by the files' SHA-256 and the
 * teacher's guidance, §21.12), the cost shown before every read, and a
 * Gemini key needed only when the school has not read the files before.
 *
 * - request(): POST /exams/{id}/import. Every call records an exam_imports
 *   row. A cached read is applied at once (200); otherwise the read is
 *   queued (ReadExamDocumentJob, 202) and applied when it is done.
 * - estimate(): POST /exams/{id}/import/estimate.
 * - process(): the job's one Gemini call, then every import waiting for it.
 * - apply(): the read becomes sections and draft questions (origin
 *   document, approved_at NULL) after the exam's last section; every
 *   question and every key needs the teacher's approval before printing.
 *   Questions past the exam's limits are reported in skipped. Figures are
 *   cropped by ExamFigures.
 */
final class ExamDocuments
{
    /** A read still `queued` after this long is presumed lost and queued again. */
    private const STALE_MINUTES = 15;

    public const INPUT_FIELDS = ['document_ids', 'page_from', 'page_to', 'guidance'];

    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly ExamDocumentReader $reader,
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {document_ids[], page_from?, page_to?, guidance?}
     * @return array{extraction: DocumentExtraction, import: ExamImport, cached: bool, estimate: array{input_tokens: int, output_tokens: int, thb: float|null}, applied: array{sections: int, questions: int, skipped: list<array{number: int|null, reason_th: string}>}|null}
     *
     * @throws ApiException
     */
    public function request(Assignment $exam, User $teacher, array $input): array
    {
        [$selection, $guidance] = $this->resolve($teacher, $input);
        AssignmentLocked::run($exam->id, fn (Assignment $locked) => ExamEditor::assertUnlocked($locked));
        $estimate = CostEstimate::forPages($selection->pageCount());
        $hash = TeacherGuidance::cacheKey($selection->inputHash(), $guidance);

        $existing = $this->cached($teacher, $hash);
        if ($existing?->isDone()) {
            $import = $this->record($exam, $teacher, $existing, $selection);

            return ['extraction' => $existing, 'import' => $import->refresh(), 'cached' => true, 'estimate' => $estimate, 'applied' => $this->apply($import)];
        }

        if ($this->keys->forTeacher($teacher->id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }
        $selection->files(); // still stored, not too large, range cuttable: 422 now rather than a failed job

        $extraction = $existing ?? DocumentExtraction::createOrFirst(
            ['school_id' => $teacher->school_id, 'input_hash' => $hash, 'purpose' => DocumentExtraction::PURPOSE_EXAM],
            ['status' => DocumentExtraction::STATUS_QUEUED, 'requested_by' => $teacher->id, 'guidance' => $guidance],
        );
        $import = $this->record($exam, $teacher, $extraction, $selection);
        if ($extraction->isDone()) {
            return ['extraction' => $extraction, 'import' => $import->refresh(), 'cached' => true, 'estimate' => $estimate, 'applied' => $this->apply($import)];
        }

        $running = $extraction->status === DocumentExtraction::STATUS_QUEUED
            && ! $extraction->wasRecentlyCreated
            && $extraction->updated_at !== null
            && $extraction->updated_at->gt(now()->subMinutes(self::STALE_MINUTES));
        if (! $running) {
            // New, failed before, or lost: (re)queue it. The import waits for it.
            $extraction->forceFill(['status' => DocumentExtraction::STATUS_QUEUED, 'error' => null, 'requested_by' => $teacher->id, 'updated_at' => now()])->save();
            ReadExamDocumentJob::dispatch($extraction->id, $selection->ids(), $selection->pageFrom, $selection->pageTo, $teacher->id, $exam->id);
        }

        return ['extraction' => $extraction->refresh(), 'import' => $import->refresh(), 'cached' => false, 'estimate' => $estimate, 'applied' => null];
    }

    /**
     * @param  array<string, mixed>  $input  {document_ids[], page_from?, page_to?, guidance?}
     * @return array{pages: int, cached: bool, estimate: array{input_tokens: int, output_tokens: int, thb: float|null}}
     *
     * @throws ApiException
     */
    public function estimate(User $teacher, array $input): array
    {
        [$selection, $guidance] = $this->resolve($teacher, $input);

        return [
            'pages' => $selection->pageCount(),
            'cached' => (bool) $this->cached($teacher, TeacherGuidance::cacheKey($selection->inputHash(), $guidance))?->isDone(),
            'estimate' => CostEstimate::forPages($selection->pageCount()),
        ];
    }

    /**
     * One read (the job's work), then every import that waits for it.
     *
     * @param  list<int>  $documentIds
     *
     * @throws GeminiException transport error while retries are left
     */
    public function process(int $extractionId, array $documentIds, ?int $pageFrom, ?int $pageTo, int $teacherId, ?int $assignmentId, bool $lastAttempt): void
    {
        $extraction = DocumentExtraction::query()->find($extractionId);
        if ($extraction === null) {
            return;
        }
        if (! $extraction->isDone()) {
            if (! $this->read($extraction, $documentIds, $pageFrom, $pageTo, $teacherId, $assignmentId, $lastAttempt)) {
                return;
            }
        }

        $waiting = ExamImport::query()->where('extraction_id', $extraction->id)->whereNull('applied_at')->orderBy('id')->get();
        foreach ($waiting as $import) {
            try {
                $this->apply($import);
            } catch (ApiException $e) {
                // Printed (structure locked) or closed meanwhile: the teacher asks again later (cached, free).
                Log::info('exam_import.not_applied', ['import_id' => $import->id, 'code' => $e->errorCode]);
            }
        }
    }

    /** After the job ran out of tries. */
    public function giveUp(int $extractionId): void
    {
        $extraction = DocumentExtraction::query()->find($extractionId);
        if ($extraction !== null && $extraction->status === DocumentExtraction::STATUS_QUEUED) {
            $this->fail($extraction, 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง');
        }
    }

    /**
     * The done read of the import becomes sections and draft questions of
     * its exam, once. Null when it was applied before or is not done.
     *
     * @return array{sections: int, questions: int, skipped: list<array{number: int|null, reason_th: string}>}|null
     *
     * @throws ApiException 409 exam_structure_locked / assignment_closed
     */
    public function apply(ExamImport $import): ?array
    {
        $outcome = AssignmentLocked::run($import->assignment_id, function (Assignment $exam) use ($import) {
            $import = ExamImport::query()->with('extraction')->findOrFail($import->id);
            $extraction = $import->extraction;
            if ($import->applied_at !== null || $extraction === null || ! $extraction->isDone() || ! is_array($extraction->result)) {
                return null;
            }
            ExamEditor::assertUnlocked($exam);

            $documents = SourceDocument::query()->whereIn('id', $import->documentIds() ?: [0])->pluck('id', 'sha256');
            $result = $extraction->result;
            $append = new ExamAppend($exam);
            $skipped = array_values(array_map(fn ($s) => ['number' => $s['number'] ?? null, 'reason_th' => (string) ($s['reason_th'] ?? '')], (array) ($result['skipped'] ?? [])));
            $full = 'ข้อสอบมีจำนวนข้อหรือจำนวนตอนครบตามที่กำหนดแล้ว';
            $sections = 0;
            $questions = 0;

            foreach ((array) ($result['sections'] ?? []) as $read) {
                $items = (array) ($read['questions'] ?? []);
                if ($items === [] || $append->questionRoom() < 1 || $append->sectionRoom() < 1) {
                    foreach ($items as $item) {
                        $skipped[] = ['number' => $item['number'] ?? null, 'reason_th' => $full];
                    }

                    continue;
                }
                $section = $append->section(self::sectionAttributes($read));
                $sections++;
                foreach ($items as $item) {
                    if ($append->questionRoom() < 1) {
                        $skipped[] = ['number' => $item['number'] ?? null, 'reason_th' => $full];

                        continue;
                    }
                    $options = [];
                    foreach ((array) ($item['options'] ?? []) as $option) {
                        $options[(int) $option['position']] = [
                            'text' => $option['text'] ?? null,
                            'figure_source' => self::figureSource($option['figure'] ?? null, $documents->all()),
                        ];
                    }
                    $append->question($section, [
                        'prompt_text' => (string) ($item['text'] ?? ''),
                        'max_points' => $section->default_points,
                        'answer_key' => self::answerKey($section, $item['answer'] ?? null),
                        'origin' => Question::ORIGIN_DOCUMENT,
                        'approved_at' => null,
                        'lock_options_suggested' => $section->type === ExamSection::TYPE_MCQ && ($item['lock_options'] ?? false) === true,
                        'figure_source' => self::figureSource($item['figure'] ?? null, $documents->all()),
                    ], $options);
                    $questions++;
                }
            }

            $append->finish();
            $import->applied_at = now();
            $import->save();

            return ['sections' => $sections, 'questions' => $questions, 'skipped' => $skipped];
        });

        if ($outcome !== null) {
            ExamFigures::queueServerPages(Assignment::query()->findOrFail($import->assignment_id));
        }

        return $outcome;
    }

    /**
     * @param  list<int>  $documentIds
     */
    private function read(DocumentExtraction $extraction, array $documentIds, ?int $pageFrom, ?int $pageTo, int $teacherId, ?int $assignmentId, bool $lastAttempt): bool
    {
        $key = $this->keys->forTeacher($teacherId);
        if ($key === null) {
            $this->fail($extraction, 'ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง');

            return false;
        }

        $documents = SourceDocument::query()->whereIn('id', $documentIds === [] ? [0] : $documentIds)->get()->keyBy('id');
        $selection = new DocumentSelection(
            array_values(array_filter(array_map(fn (int $id) => $documents[$id] ?? null, $documentIds))),
            $pageFrom,
            $pageTo,
        );
        try {
            $files = $selection->files();
        } catch (ApiException $e) {
            $this->fail($extraction, $e->getMessage());

            return false;
        }
        $refs = [];
        foreach ($selection->documents as $i => $document) {
            $refs[] = [
                'sha256' => $document->sha256,
                'pages' => $files[$i]['page_count'],
                'page_offset' => $selection->hasRange() ? (int) $selection->pageFrom - 1 : 0,
            ];
        }

        try {
            $result = $this->reader->read($files, $refs, $key, $extraction->guidance, $extraction->guidance === null ? null : $extraction->requested_by, $assignmentId);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR && ! $lastAttempt) {
                throw $e; // transient: the queue retries with backoff
            }
            Log::warning('exam_document.failed', ['extraction_id' => $extraction->id, 'status' => $e->status]);
            $this->fail($extraction, match ($e->status) {
                GeminiException::INVALID_OUTPUT => 'AI อ่านไฟล์ข้อสอบไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น แนบไฟล์ใหม่ หรือพิมพ์ข้อในแอปเอง',
                GeminiException::KEY_INVALID => 'Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าแล้วลองอีกครั้ง',
                default => 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง',
            });

            return false;
        }

        $extraction->forceFill([
            'status' => DocumentExtraction::STATUS_DONE,
            'result' => $result,
            'model' => Str::limit($this->gateway->client()->model(), 64, ''),
            'prompt_version' => $this->prompts->get(ExamDocumentReader::PURPOSE, 'general')->versionLabel(),
            'error' => null,
        ])->save();

        return true;
    }

    private function record(Assignment $exam, User $teacher, DocumentExtraction $extraction, DocumentSelection $selection): ExamImport
    {
        return ExamImport::create([
            'assignment_id' => $exam->id,
            'extraction_id' => $extraction->id,
            'documents' => array_map(fn (SourceDocument $d) => [
                'source_document_id' => $d->id,
                'page_from' => $selection->hasRange() ? (int) $selection->pageFrom : 1,
                'page_to' => $selection->hasRange() ? (int) $selection->pageTo : $d->page_count,
            ], $selection->documents),
            'requested_by' => $teacher->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $read
     * @return array<string, mixed>
     */
    private static function sectionAttributes(array $read): array
    {
        $type = (string) $read['type'];
        $numeric = is_array($read['numeric'] ?? null) ? $read['numeric'] : [];

        return [
            'title' => $read['title'] ?? null,
            'instructions' => $read['instructions'] ?? null,
            'type' => $type,
            'option_count' => $type === ExamSection::TYPE_MCQ ? (int) ($read['option_count'] ?? 4) : null,
            'numeric_digits' => $type === ExamSection::TYPE_NUMERIC ? (int) ($numeric['digits'] ?? 3) : null,
            'numeric_allow_negative' => $type === ExamSection::TYPE_NUMERIC && ($numeric['allow_negative'] ?? false) === true,
            'numeric_allow_decimal' => $type === ExamSection::TYPE_NUMERIC && ($numeric['allow_decimal'] ?? false) === true,
            'default_points' => 1,
        ];
    }

    /**
     * The read answer as the section's key, or null when it does not fit.
     *
     * @return array<string, list<int|string>>|null
     */
    private static function answerKey(ExamSection $section, mixed $answer): ?array
    {
        try {
            return ExamAnswerKey::normalise($section, is_array($answer) ? $answer : null, 'answer_key.');
        } catch (ValidationException) {
            return null; // e.g. a number longer than the digit block: the teacher types it
        }
    }

    /**
     * @param  array<string, int>  $documents  sha256 => source_document_id of the import
     * @return array{source_document_id: int, page_no: int, box_2d: list<int>, page_image_id: null}|null
     */
    private static function figureSource(mixed $figure, array $documents): ?array
    {
        if (! is_array($figure) || ! isset($documents[$figure['sha256'] ?? ''])) {
            return null;
        }
        $box = ExamFigures::box($figure['box_2d'] ?? null);

        return $box === null ? null : [
            'source_document_id' => (int) $documents[$figure['sha256']],
            'page_no' => (int) ($figure['page'] ?? 1),
            'box_2d' => $box,
            'page_image_id' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: DocumentSelection, 1: string|null}
     *
     * @throws ApiException
     */
    private function resolve(User $teacher, array $input): array
    {
        $ids = $input['document_ids'] ?? null;
        if (! is_array($ids) || $ids === []) {
            throw new ApiException('กรุณาแนบไฟล์ข้อสอบอย่างน้อย 1 ไฟล์', 'validation_failed', 422, ['document_ids' => ['กรุณาแนบไฟล์ข้อสอบอย่างน้อย 1 ไฟล์']]);
        }

        return [DocumentSelection::resolve($teacher, $input), TeacherGuidance::fromInput($input)];
    }

    private function cached(User $teacher, string $hash): ?DocumentExtraction
    {
        return DocumentExtraction::query()
            ->where('school_id', $teacher->school_id)
            ->where('input_hash', $hash)
            ->where('purpose', DocumentExtraction::PURPOSE_EXAM)
            ->first();
    }

    private function fail(DocumentExtraction $extraction, string $message): void
    {
        $extraction->forceFill(['status' => DocumentExtraction::STATUS_FAILED, 'error' => Str::limit($message, 250)])->save();
    }
}
