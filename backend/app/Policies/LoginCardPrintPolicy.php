<?php

namespace App\Policies;

use App\Models\LoginCardPrint;
use App\Models\User;

/**
 * The PDF of QR login cards is a credential: only the teacher who requested it
 * (or who teaches that classroom) may poll its status or download it. Files
 * have no public URL (DESIGN §7.3).
 */
class LoginCardPrintPolicy
{
    public function view(User $user, LoginCardPrint $print): bool
    {
        if (! $user->isTeacher() || ! $user->isActive() || $print->school_id !== $user->school_id) {
            return false;
        }

        if ($print->requested_by === $user->id) {
            return true;
        }

        return $print->classroom_id !== null && $print->classroom?->teacher_id === $user->id;
    }

    public function download(User $user, LoginCardPrint $print): bool
    {
        return $this->view($user, $print);
    }
}
