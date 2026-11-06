<?php

namespace App\Domain\Firestore;

use App\Models\User;

/** The Firebase uid of a Krucheck user: `u{users.id}` (DESIGN §38.2). */
final class FirestoreUid
{
    public static function of(User|int $user): string
    {
        return 'u'.($user instanceof User ? $user->id : $user);
    }

    /** `users/{uid}` as a document path. */
    public static function path(User|int $user): string
    {
        return 'users/'.self::of($user);
    }
}
