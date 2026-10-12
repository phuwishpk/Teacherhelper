<?php

namespace App\Domain\Google;

use App\Models\ClassroomGoogleTopic;

/**
 * The Classroom topic a material or an assignment is filed under (DESIGN
 * §30.1, §30.11): the one remembered for the unit or the name, else the
 * course's topic of that name, else a new one.
 */
final class ClassroomTopics
{
    /**
     * @throws GoogleApiException
     */
    public static function idFor(GoogleApi $api, string $courseId, string $name, ?int $unitId): string
    {
        $name = mb_substr(trim($name), 0, 100);
        $row = $unitId === null ? null : ClassroomGoogleTopic::query()->where('course_id', $courseId)->where('unit_id', $unitId)->first();
        $row ??= ClassroomGoogleTopic::query()->where('course_id', $courseId)->where('name', $name)->first();
        if ($row !== null) {
            if ($unitId !== null && $row->unit_id === null) {
                $row->forceFill(['unit_id' => $unitId])->save();
            }

            return $row->topic_id;
        }
        $topicId = null;
        foreach ($api->topics($courseId) as $topic) {
            if ($topic['name'] === $name && $topic['id'] !== '') {
                $topicId = $topic['id'];
                break;
            }
        }
        $topicId ??= $api->createTopic($courseId, $name);
        ClassroomGoogleTopic::query()->updateOrCreate(
            ['course_id' => $courseId, 'topic_id' => mb_substr($topicId, 0, 64)],
            ['name' => $name, 'unit_id' => $unitId],
        );

        return $topicId;
    }

    /** The topic was deleted on the Classroom website: forget it so the next send makes it again. */
    public static function forget(string $courseId, string $topicId): void
    {
        ClassroomGoogleTopic::query()->where('course_id', $courseId)->where('topic_id', $topicId)->delete();
    }
}
