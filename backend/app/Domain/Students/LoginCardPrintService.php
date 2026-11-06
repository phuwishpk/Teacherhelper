<?php

namespace App\Domain\Students;

use App\Jobs\RenderLoginCardsJob;
use App\Models\Classroom;
use App\Models\LoginCardPrint;
use App\Models\User;

/**
 * Creates the `login_card_prints` row and queues RenderLoginCardsJob (queue
 * `pdf`, DESIGN §7.2). Only the SHA-256 of a QR token is stored, so a card can
 * only be printed by issuing a new token: rendering a print rotates the QR
 * token of every student on it and revokes their sessions (DESIGN §7.4).
 */
class LoginCardPrintService
{
    /**
     * @param  list<int>|null  $studentIds  only these students of the classroom (DESIGN §24.4); null = all
     */
    public function queueForClassroom(Classroom $classroom, User $teacher, ?array $studentIds = null): LoginCardPrint
    {
        $print = LoginCardPrint::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'student_id' => null,
            'requested_by' => $teacher->id,
            'status' => LoginCardPrint::STATUS_QUEUED,
        ]);

        RenderLoginCardsJob::dispatch($print->id, $studentIds);

        return $print;
    }

    public function queueForStudent(User $student, User $teacher): LoginCardPrint
    {
        $print = LoginCardPrint::create([
            'school_id' => $student->school_id,
            'classroom_id' => null,
            'student_id' => $student->id,
            'requested_by' => $teacher->id,
            'status' => LoginCardPrint::STATUS_QUEUED,
        ]);

        RenderLoginCardsJob::dispatch($print->id);

        return $print;
    }

    /** Path on the private disk (DESIGN §7.3: no public URL, controller streams it). */
    public static function filePath(LoginCardPrint $print): string
    {
        return 'login-cards/'.$print->school_id.'/'.$print->id.'.pdf';
    }
}
