<?php

namespace App\Domain\Practice;

use App\Domain\Gemini\PracticeDraft;
use App\Exceptions\ApiException;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\PracticeItem;
use App\Models\Skill;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The school's practice bank (DESIGN §9.6, §14.1): Gemini drafts and
 * teacher-written items, edited and approved by teachers. Approving needs a
 * teacher who teaches the skill's subject (has an assignment in it in one
 * of their classrooms); any active teacher of the school may edit, retire
 * or write items.
 */
final class PracticeBank
{
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
     * as a whole. A status change to approved stamps approved_by/at.
     *
     * @param  array<string, mixed>  $changes
     */
    public function update(User $teacher, PracticeItem $item, array $changes): PracticeItem
    {
        $current = [
            'answer_type' => $item->answer_type,
            'prompt_text' => $item->prompt_text,
            'options' => $item->options,
            'answer_key' => $item->answer_key,
            'explanation' => $item->explanation,
            'status' => $item->status,
        ];
        $merged = array_merge($current, array_intersect_key($changes, array_flip(PracticeItemData::FIELDS)));
        if (($merged['answer_type'] ?? null) !== PracticeItem::TYPE_MCQ) {
            $merged['options'] = null;
        }
        $data = PracticeItemData::validate($merged);

        return DB::transaction(function () use ($teacher, $item, $data) {
            $item = PracticeItem::query()->lockForUpdate()->findOrFail($item->id);
            $status = $data['status'];
            unset($data['status']);
            $item->fill($data);
            if ($status !== $item->status) {
                $this->applyStatus($item, $teacher, $status);
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
            if (! self::teachesSubjectOf($teacher, $item->skill_id)) {
                throw new ApiException('อนุมัติได้เฉพาะครูที่สอนวิชาของทักษะนี้ (มีการบ้านในวิชานี้ในห้องที่สอน)', 'subject_not_taught', 403);
            }
            $item->approved_by = $teacher->id;
            $item->approved_at = now();
        } elseif ($status === PracticeItem::STATUS_DRAFT) {
            $item->approved_by = null;
            $item->approved_at = null;
        }
        $item->status = $status;
    }

    /** The teacher has an assignment in the skill's subject in a classroom they teach. */
    public static function teachesSubjectOf(User $teacher, int $skillId): bool
    {
        $subjectId = Skill::query()->whereKey($skillId)->value('subject_id');
        if ($subjectId === null) {
            return false;
        }

        return Assignment::query()
            ->where('school_id', $teacher->school_id)
            ->where('subject_id', $subjectId)
            ->whereIn('classroom_id', Classroom::query()->select('id')->where('teacher_id', $teacher->id))
            ->exists();
    }
}
