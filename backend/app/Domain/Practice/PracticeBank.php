<?php

namespace App\Domain\Practice;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Gemini\PracticeDraft;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The school's practice bank (DESIGN §9.6, §14.1): Gemini drafts and
 * teacher-written items, edited and approved by teachers. Approving needs a
 * teacher who teaches the skill's subject (has an assignment in it in one
 * of their classrooms); any active teacher of the school may write items,
 * edit drafts, retire items or send one back to draft. The content of an
 * item that stays approved (prompt, options, answer key, explanation) is
 * changed only by a subject teacher, who thereby re-approves it: otherwise
 * a colleague refused approval could rewrite the answer key of an approved
 * item behind the approver's back.
 */
final class PracticeBank
{
    /** What students see and are graded by: every field but status. */
    public const CONTENT_FIELDS = ['answer_type', 'prompt_text', 'options', 'answer_key', 'explanation'];

    /**
     * Stores the validated output of `practice_gen` as drafts (source ai).
     *
     * @return list<PracticeItem>
     */
    public function storeDrafts(int $schoolId, int $skillId, PracticeDraft $draft): array
    {
        return DB::transaction(function () use ($schoolId, $skillId, $draft) {
            $items = [];
            foreach ($draft->items as $item) {
                $items[] = PracticeItem::create([
                    'school_id' => $schoolId,
                    'skill_id' => $skillId,
                    'source' => PracticeItem::SOURCE_AI,
                    'status' => PracticeItem::STATUS_DRAFT,
                    ...$item,
                ]);
            }

            return $items;
        });
    }

    /**
     * A teacher-written item (source teacher).
     *
     * @param  array<string, mixed>  $input
     */
    public function create(User $teacher, Skill $skill, array $input): PracticeItem
    {
        $data = PracticeItemData::validate($input);
        $item = new PracticeItem([
            'school_id' => $teacher->school_id,
            'skill_id' => $skill->id,
            'source' => PracticeItem::SOURCE_TEACHER,
            ...$data,
        ]);
        $this->applyStatus($item, $teacher, $data['status']);
        $item->save();

        return $item;
    }

    /**
     * PATCH: the changed fields merged over the current item, then validated
     * as a whole. A status change to approved stamps approved_by/at. A
     * content change of an item that stays approved needs a subject teacher
     * (403 subject_not_taught) and stamps approved_by/at with them; sending
     * the same content back (whatever the key order) is not a change.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(User $teacher, PracticeItem $item, array $changes): PracticeItem
    {
        $current = self::content($item) + ['status' => $item->status];
        $merged = array_merge($current, array_intersect_key($changes, array_flip(PracticeItemData::FIELDS)));
        if (($merged['answer_type'] ?? null) !== PracticeItem::TYPE_MCQ) {
            $merged['options'] = null;
        }
        $data = PracticeItemData::validate($merged);

        return DB::transaction(function () use ($teacher, $item, $data) {
            $item = PracticeItem::query()->lockForUpdate()->findOrFail($item->id);
            $status = $data['status'];
            unset($data['status']);
            $contentChanged = self::canonical(self::content($item)) !== self::canonical(array_intersect_key($data, array_flip(self::CONTENT_FIELDS)));
            $item->fill($data);
            if ($status !== $item->status) {
                $this->applyStatus($item, $teacher, $status);
            } elseif ($contentChanged && $item->status === PracticeItem::STATUS_APPROVED) {
                $this->approve($item, $teacher, 'แก้เนื้อหาข้อที่อนุมัติแล้วได้เฉพาะครูที่สอนวิชาของทักษะนี้ ครูท่านอื่นเปลี่ยนสถานะเป็น draft ก่อนแล้วค่อยแก้');
            }
            $item->save();

            return $item;
        });
    }

    /**
     * @throws ApiException 403 subject_not_taught
     */
    private function applyStatus(PracticeItem $item, User $teacher, string $status): void
    {
        if ($status === PracticeItem::STATUS_APPROVED) {
            $this->approve($item, $teacher, 'อนุมัติได้เฉพาะครูที่สอนวิชาของทักษะนี้ (มีการบ้านในวิชานี้ในห้องที่สอน)');
        } elseif ($status === PracticeItem::STATUS_DRAFT) {
            $item->approved_by = null;
            $item->approved_at = null;
        }
        $item->status = $status;
    }

    /**
     * Stamps approved_by/at with the teacher, who must teach the skill's subject.
     *
     * @throws ApiException 403 subject_not_taught
     */
    private function approve(PracticeItem $item, User $teacher, string $refusal): void
    {
        if (! self::teachesSubjectOf($teacher, $item->skill_id)) {
            throw new ApiException($refusal, 'subject_not_taught', 403);
        }
        $item->approved_by = $teacher->id;
        $item->approved_at = now();
    }

    /** The teacher manages an assignment in the skill's subject (DESIGN §24.8). */
    public static function teachesSubjectOf(User $teacher, int $skillId): bool
    {
        $subjectId = Skill::query()->whereKey($skillId)->value('subject_id');
        if ($subjectId === null) {
            return false;
        }

        return ClassroomAccess::managedAssignments($teacher)
            ->where('assignments.subject_id', $subjectId)
            ->exists();
    }

    /**
     * @return array<string, mixed>
     */
    private static function content(PracticeItem $item): array
    {
        return [
            'answer_type' => $item->answer_type,
            'prompt_text' => $item->prompt_text,
            'options' => $item->options,
            'answer_key' => $item->answer_key,
            'explanation' => $item->explanation,
        ];
    }

    /**
     * JSON with object keys sorted at every level, so that key order and
     * 1 versus 1.0 (json_encode writes both as 1) do not count as a change.
     */
    private static function canonical(mixed $value): string
    {
        return (string) json_encode(self::sorted($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private static function sorted(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        return array_map(self::sorted(...), $value);
    }
}
