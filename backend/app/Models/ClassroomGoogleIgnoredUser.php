<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * DESIGN §19.8 `classroom_google_ignored_users`: a Google Classroom account
 * the teacher removed when importing the course (a test or parent account),
 * so a roster sync never adds it back. Composite key; written with insert().
 *
 * @property int $classroom_id
 * @property string $google_user_id
 * @property string $name
 * @property Carbon|null $created_at
 */
class ClassroomGoogleIgnoredUser extends Model
{
    public const UPDATED_AT = null;

    public $incrementing = false;

    protected $primaryKey = 'google_user_id';

    protected $keyType = 'string';

    protected $fillable = [
        'classroom_id',
        'google_user_id',
        'name',
    ];
}
