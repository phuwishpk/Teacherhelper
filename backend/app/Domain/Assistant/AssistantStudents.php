<?php

namespace App\Domain\Assistant;

use App\Domain\Classrooms\ClassroomAccess;
use App\Domain\Google\NameNormalizer;
use App\Models\AssistantConversation;
use App\Models\AssistantStudentLabel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Students in an assistant conversation (DESIGN §37.8): each one gets a
 * label of that conversation ("S1", "S2" …) and Gemini only ever sees the
 * label. The teacher's own words are rewritten before they are sent: the
 * full name, the name without its title, and a first name of at least
 * MIN_FIRST_NAME characters that only one of the teacher's students has
 * become that student's label. The stored message keeps what the teacher
 * typed; the app shows names in place of labels.
 */
final class AssistantStudents
{
    public const MIN_FIRST_NAME = 3;

    /** @var array<int, list<array{0: string, 1: int, 2: int}>> by teacher: [name, student, classroom], longest first */
    private array $names = [];

    /** The label of a student in the conversation, made on first use. */
    public function label(AssistantConversation $conversation, int $studentId, ?int $classroomId = null): string
    {
        $row = AssistantStudentLabel::query()->where('conversation_id', $conversation->id)->where('student_id', $studentId)->first();
        if ($row !== null) {
            return $row->label;
        }

        return DB::transaction(function () use ($conversation, $studentId, $classroomId) {
            AssistantConversation::query()->whereKey($conversation->id)->lockForUpdate()->first();
            $next = AssistantStudentLabel::query()->where('conversation_id', $conversation->id)->count() + 1;

            return AssistantStudentLabel::create([
                'conversation_id' => $conversation->id,
                'label' => 'S'.$next,
                'student_id' => $studentId,
                'classroom_id' => $classroomId,
            ])->label;
        });
    }

    /** The student a label stands for, or null. */
    public function student(AssistantConversation $conversation, string $label): ?AssistantStudentLabel
    {
        return AssistantStudentLabel::query()->where('conversation_id', $conversation->id)->where('label', strtoupper(trim($label)))->first();
    }

    /** $text with the names of the teacher's students replaced by labels. */
    public function pseudonymise(User $teacher, AssistantConversation $conversation, string $text): string
    {
        foreach ($this->names($teacher) as [$name, $studentId, $classroomId]) {
            if (mb_stripos($text, $name) !== false) {
                $text = str_ireplace($name, $this->label($conversation, $studentId, $classroomId), $text);
            }
        }

        return $text;
    }

    /**
     * The labels of a conversation with the names the teacher sees.
     *
     * @return list<array{label: string, student_id: int, name: string|null, classroom_id: int|null}>
     */
    public static function api(AssistantConversation $conversation): array
    {
        $labels = $conversation->labels()->get();
        $names = User::query()->whereIn('id', $labels->pluck('student_id'))->pluck('name', 'id');

        return $labels->map(fn (AssistantStudentLabel $l) => [
            'label' => $l->label,
            'student_id' => $l->student_id,
            'name' => $names[$l->student_id] ?? null,
            'classroom_id' => $l->classroom_id,
        ])->values()->all();
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function names(User $teacher): array
    {
        if (isset($this->names[$teacher->id])) {
            return $this->names[$teacher->id];
        }
        $rows = DB::table('classroom_students')
            ->join('users', 'users.id', '=', 'classroom_students.student_id')
            ->whereIn('classroom_students.classroom_id', ClassroomAccess::classrooms($teacher)->select('classrooms.id'))
            ->orderBy('classroom_students.classroom_id')
            ->get(['users.id', 'users.name', 'classroom_students.classroom_id']);
        $out = [];
        $firsts = [];
        foreach ($rows as $row) {
            $name = trim((string) $row->name);
            if ($name === '') {
                continue;
            }
            $plain = NameNormalizer::withoutTitle($name);
            foreach (array_unique([$name, $plain]) as $form) {
                if (mb_strlen($form) >= self::MIN_FIRST_NAME) {
                    $out[$form] ??= [$form, (int) $row->id, (int) $row->classroom_id];
                }
            }
            $first = NameNormalizer::firstName($name);
            if (mb_strlen($first) >= self::MIN_FIRST_NAME) {
                $firsts[$first][(int) $row->id] = (int) $row->classroom_id;
            }
        }
        foreach ($firsts as $first => $students) {
            if (count($students) === 1 && ! isset($out[$first])) {
                $out[$first] = [$first, (int) array_key_first($students), (int) reset($students)];
            }
        }
        $out = array_values($out);
        usort($out, fn (array $a, array $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $this->names[$teacher->id] = $out;
    }
}
