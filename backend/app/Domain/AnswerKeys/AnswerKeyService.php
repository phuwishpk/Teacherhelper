<?php

namespace App\Domain\AnswerKeys;

use App\Domain\Assignments\AssignmentLocked;
use App\Domain\Documents\CostEstimate;
use App\Domain\Documents\DocumentSelection;
use App\Domain\Gemini\GeminiException;
use App\Domain\Gemini\GeminiGateway;
use App\Domain\Gemini\GeminiKeyResolver;
use App\Domain\Gemini\PromptRepository;
use App\Domain\Gemini\RubricDraftRequest;
use App\Exceptions\ApiException;
use App\Jobs\DraftAnswerKeyJob;
use App\Jobs\ExtractDocumentJob;
use App\Jobs\ReleaseWaitingSubmissionsJob;
use App\Models\Assignment;
use App\Models\DocumentExtraction;
use App\Models\Question;
use App\Models\SourceDocument;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The teacher's answer key (DESIGN §19.5, §21.2):
 *
 * request(): POST /answer-key/extract (read the teacher's key from
 * documents) and POST /answer-key/draft (AI drafts the answers, from the
 * typed questions and/or a question sheet). The result is looked up in
 * document_extractions first, shared by the whole school (key: the files'
 * hash, see DocumentSelection; a draft hashes a "draft|" prefix, the
 * questions, subject and grade as well): a hit is applied at once without
 * calling Gemini (200, cached); a miss needs a usable Gemini key (422
 * ai_key_missing), is queued (ExtractDocumentJob / DraftAnswerKeyJob, 202)
 * and becomes the assignment's key_extraction_id, whose status GET
 * /answer-key reports.
 *
 * process(): the job's work: one Gemini call (AnswerKeyReader), the result
 * cached, then written into the questions (AnswerKeyApplier) of the
 * assignment still waiting for it.
 *
 * estimate(): POST /answer-key/estimate, the same lookup without queueing
 * anything: pages, cost estimate and whether the cache already has it.
 *
 * approve(): POST /answer-key/approve: every question complete
 * (KeyCompleteness), key_approved_at set, a freeform draft becomes ready,
 * and submissions that waited for the key are graded
 * (ReleaseWaitingSubmissionsJob).
 */
final class AnswerKeyService
{
    public function __construct(
        private readonly GeminiKeyResolver $keys,
        private readonly AnswerKeyApplier $applier,
        private readonly AnswerKeyReader $reader,
        private readonly GeminiGateway $gateway,
        private readonly PromptRepository $prompts,
    ) {}

    /**
     * @param  array<string, mixed>  $input  {document_ids[], page_from?, page_to?}
     * @return array{extraction: DocumentExtraction, cached: bool, applied: array<string, mixed>|null, estimate: array<string, mixed>}
     *
     * @throws ApiException
     */
    public function request(User $teacher, Assignment $assignment, string $kind, array $input): array
    {
        $assignment = Assignment::query()->with(['classroom', 'subject'])->findOrFail($assignment->id);
        if ($assignment->isClosed()) {
            throw new ApiException('การบ้านนี้ปิดแล้ว แก้ไขไม่ได้', 'assignment_closed', 409);
        }
        $read = $kind === AnswerKeyResult::KIND_READ;
        $selection = DocumentSelection::resolve($teacher, $input, required: $read);
        $questions = $assignment->questions()->get();
        if (! $read && $selection->isEmpty() && $questions->isEmpty()) {
            throw new ApiException('ยังไม่มีคำถาม พิมพ์โจทย์หรือแนบใบโจทย์ก่อนให้ AI ร่างเฉลย', 'assignment_empty', 422);
        }

        $hash = $read ? $selection->inputHash() : self::draftHash($assignment, $selection, $questions->all());
        $estimate = CostEstimate::forPages($selection->pageCount(), $questions->isEmpty() ? null : $questions->count());
        $existing = DocumentExtraction::query()
            ->where('school_id', $assignment->school_id)
            ->where('input_hash', $hash)
            ->where('purpose', DocumentExtraction::PURPOSE_ANSWER_KEY)
            ->first();

        if ($existing?->isDone()) {
            return ['extraction' => $existing, 'cached' => true, 'applied' => $this->applyTo($assignment, $existing), 'estimate' => $estimate];
        }

        if ($this->keys->forTeacher($assignment->classroom?->teacher_id) === null) {
            throw new ApiException('ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง', 'ai_key_missing', 422);
        }
        $selection->files(); // files still stored, not too large, range cuttable: 422 now rather than a failed job

        $extraction = $existing ?? DocumentExtraction::createOrFirst(
            ['school_id' => $assignment->school_id, 'input_hash' => $hash, 'purpose' => DocumentExtraction::PURPOSE_ANSWER_KEY],
            ['status' => DocumentExtraction::STATUS_QUEUED, 'requested_by' => $teacher->id],
        );
        if ($extraction->isDone()) {
            return ['extraction' => $extraction, 'cached' => true, 'applied' => $this->applyTo($assignment, $extraction), 'estimate' => $estimate];
        }
        if ($extraction->status === DocumentExtraction::STATUS_FAILED) {
            $extraction->forceFill(['status' => DocumentExtraction::STATUS_QUEUED, 'error' => null, 'requested_by' => $teacher->id])->save();
        }

        AssignmentLocked::run($assignment->id, function (Assignment $locked) use ($extraction) {
            $locked->key_extraction_id = $extraction->id;
            $locked->save();
        });

        $args = [$extraction->id, $assignment->id, $selection->ids(), $selection->pageFrom, $selection->pageTo];
        $read ? ExtractDocumentJob::dispatch(...$args) : DraftAnswerKeyJob::dispatch(...$args);

        return ['extraction' => $extraction->refresh(), 'cached' => false, 'applied' => null, 'estimate' => $estimate];
    }

    /**
     * POST /answer-key/estimate: what the same extract or draft call would
     * cost, for the files and page range the teacher picked, and whether the
     * school read it before (free). Nothing is queued and no Gemini key is
     * needed, so the app can show the estimate before every read (§19.5).
     *
     * @param  array<string, mixed>  $input  {document_ids?[], page_from?, page_to?}
     * @return array{kind: string, pages: int, cached: bool, estimate: array{input_tokens: int, output_tokens: int, thb: float|null}}
     *
     * @throws ApiException
     */
    public function estimate(User $teacher, Assignment $assignment, string $kind, array $input): array
    {
        $assignment = Assignment::query()->with(['classroom', 'subject'])->findOrFail($assignment->id);
        $read = $kind === AnswerKeyResult::KIND_READ;
        $selection = DocumentSelection::resolve($teacher, $input, required: $read);
        $questions = $assignment->questions()->get();
        if (! $read && $selection->isEmpty() && $questions->isEmpty()) {
            throw new ApiException('ยังไม่มีคำถาม พิมพ์โจทย์หรือแนบใบโจทย์ก่อนให้ AI ร่างเฉลย', 'assignment_empty', 422);
        }

        $hash = $read ? $selection->inputHash() : self::draftHash($assignment, $selection, $questions->all());
        $cached = DocumentExtraction::query()
            ->where('school_id', $assignment->school_id)
            ->where('input_hash', $hash)
            ->where('purpose', DocumentExtraction::PURPOSE_ANSWER_KEY)
            ->where('status', DocumentExtraction::STATUS_DONE)
            ->exists();

        return [
            'kind' => $kind,
            'pages' => $selection->pageCount(),
            'cached' => $cached,
            'estimate' => CostEstimate::forPages($selection->pageCount(), $questions->isEmpty() ? null : $questions->count()),
        ];
    }

    /**
     * One read or draft (the job's work).
     *
     * @param  list<int>  $documentIds
     *
     * @throws GeminiException transport error while retries are left
     */
    public function process(string $kind, int $extractionId, int $assignmentId, array $documentIds, ?int $pageFrom, ?int $pageTo, bool $lastAttempt): void
    {
        $extraction = DocumentExtraction::query()->find($extractionId);
        $assignment = Assignment::query()->with(['classroom', 'subject'])->find($assignmentId);
        if ($extraction === null || $assignment === null) {
            return;
        }
        if ($extraction->isDone()) {
            $this->applyIfWaiting($assignment, $extraction);

            return;
        }

        $key = $this->keys->forTeacher($assignment->classroom?->teacher_id);
        if ($key === null) {
            $this->fail($extraction, 'ยังไม่มี Gemini API key ให้ใช้ ใส่ key ที่หน้าตั้งค่าก่อนแล้วลองอีกครั้ง');

            return;
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

            return;
        }

        $questions = Question::query()->where('assignment_id', $assignment->id)->orderBy('position')->get()->all();
        $subject = (string) $assignment->subject?->name;
        $grade = RubricDraftRequest::gradeLabel((int) $assignment->classroom?->grade_level);
        try {
            $result = $kind === AnswerKeyResult::KIND_READ
                ? $this->reader->read($files, $questions, $subject, $grade, $key, $assignment->id)
                : $this->reader->draft($files, $questions, $subject, $grade, $key, $assignment->id);
        } catch (GeminiException $e) {
            if ($e->status === GeminiException::ERROR && ! $lastAttempt) {
                throw $e; // transient: the queue retries with backoff
            }
            Log::warning('answer_key.failed', ['extraction_id' => $extraction->id, 'kind' => $kind, 'status' => $e->status]);
            $this->fail($extraction, match ($e->status) {
                GeminiException::INVALID_OUTPUT => 'AI อ่านเฉลยไม่สำเร็จ ลองถ่ายรูปให้ชัดขึ้น แนบไฟล์ใหม่ หรือพิมพ์เฉลยเอง',
                GeminiException::KEY_INVALID => 'Gemini API key ใช้ไม่ได้ ตรวจ key ที่หน้าตั้งค่าแล้วลองอีกครั้ง',
                default => 'ติดต่อ AI ไม่ได้ ลองใหม่อีกครั้งภายหลัง',
            });

            return;
        }

        $extraction->forceFill([
            'status' => DocumentExtraction::STATUS_DONE,
            'result' => $result->toArray(),
            'model' => Str::limit($this->gateway->client()->model(), 64, ''),
            'prompt_version' => $this->prompts->get($kind, 'general')->versionLabel(),
            'error' => null,
        ])->save();

        $this->applyIfWaiting($assignment, $extraction);
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
     * @throws ApiException 422 assignment_empty / answer_key_incomplete, 409 assignment_closed
     */
    public function approve(User $teacher, Assignment $assignment): Assignment
    {
        $assignment = AssignmentLocked::run($assignment->id, function (Assignment $locked) use ($teacher) {
            KeyCompleteness::assertComplete($locked);
            $locked->key_approved_at = now();
            $locked->key_approved_by = $teacher->id;
            $locked->key_origin ??= Assignment::KEY_TEACHER;
            if ($locked->isFreeform() && $locked->isDraft()) {
                $locked->status = Assignment::STATUS_READY;
            }
            $locked->save();

            return $locked;
        });

        ReleaseWaitingSubmissionsJob::dispatch($assignment->id);
        Log::info('answer_key.approved', ['assignment_id' => $assignment->id, 'mode' => $assignment->mode]);

        return $assignment;
    }

    /**
     * @param  list<Question>  $questions
     */
    private static function draftHash(Assignment $assignment, DocumentSelection $selection, array $questions): string
    {
        $briefs = array_map(fn (Question $q) => [(int) $q->position, $q->type, trim($q->prompt_text)], $questions);

        return hash('sha256', implode('|', [
            'draft',
            $selection->inputHash(),
            json_encode($briefs, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            (string) $assignment->subject?->name,
            (string) $assignment->classroom?->grade_level,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function applyTo(Assignment $assignment, DocumentExtraction $extraction): array
    {
        AssignmentLocked::run($assignment->id, function (Assignment $locked) use ($extraction) {
            $locked->key_extraction_id = $extraction->id;
            $locked->save();
        });

        return $this->applier->apply($assignment, AnswerKeyResult::fromArray((array) $extraction->result));
    }

    private function applyIfWaiting(Assignment $assignment, DocumentExtraction $extraction): void
    {
        $assignment->refresh();
        if ($assignment->key_extraction_id !== $extraction->id || $assignment->isClosed()) {
            return; // the teacher asked for another key meanwhile, or closed the assignment
        }
        $this->applier->apply($assignment, AnswerKeyResult::fromArray((array) $extraction->result));
    }

    private function fail(DocumentExtraction $extraction, string $message): void
    {
        $extraction->forceFill(['status' => DocumentExtraction::STATUS_FAILED, 'error' => Str::limit($message, 250)])->save();
    }
}
