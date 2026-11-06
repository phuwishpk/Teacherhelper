<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * DESIGN §30.11 `classroom_google_topics`: a topic of a Classroom course
 * the app created or found, by unit (renamed with the unit) or by name.
 *
 * @property int $id
 * @property string $course_id
 * @property string $topic_id
 * @property string $name
 * @property int|null $unit_id
 */
class ClassroomGoogleTopic extends Model
{
    protected $fillable = ['course_id', 'topic_id', 'name', 'unit_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['unit_id' => 'integer'];
    }
}
