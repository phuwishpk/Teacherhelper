<?php

namespace App\Http\Middleware;

use App\Domain\Classrooms\ClosedClassrooms;
use App\Models\Classroom;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * DESIGN §24.6: a closed classroom ("ห้องเก่า") is read-only. Every write
 * request (not GET/HEAD) whose path names a row of a closed classroom
 * (`/classrooms/{id}/...`, `/assignments/{id}/...`, `/student/responses/{id}/...`
 * and the rest of ClosedClassrooms::RESOURCES) answers 409 `classroom_closed`
 * before the body is validated.
 *
 * Only a user who can see the classroom learns that it is closed: its
 * homeroom teacher, or a student enrolled in it. Anyone else passes on to the
 * controller and gets its usual 404/403, so the 409 reveals nothing (§24.8).
 *
 * Writes that name the classroom in the body call ClosedClassrooms::assertOpen()
 * themselves. Alias `classroom.open` in bootstrap/app.php.
 */
class EnsureClassroomOpen
{
    /** Writes a closed classroom still takes: reopening, deleting, and free estimates. */
    private const ALLOWED = [
        'api.classrooms.reopen',
        'api.classrooms.close',
        'api.classrooms.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }
        $name = (string) $request->route()?->getName();
        if (in_array($name, self::ALLOWED, true) || str_ends_with($name, '.estimate')) {
            return $next($request);
        }

        $segments = explode('/', (string) preg_replace('#^api/v1/#', '', $request->path()));
        if (($segments[0] ?? '') === 'student') {
            array_shift($segments);
        }
        [$resource, $id] = [$segments[0] ?? '', $segments[1] ?? ''];
        if (! ClosedClassrooms::knows($resource) || preg_match('/\A[0-9]{1,18}\z/', $id) !== 1) {
            return $next($request);
        }

        $classroomId = ClosedClassrooms::classroomIdOf($resource, (int) $id);
        $classroom = $classroomId === null ? null : Classroom::query()->find($classroomId);
        $user = $request->user();
        if ($classroom !== null && $classroom->isClosed() && $user instanceof User && self::sees($user, $classroom)) {
            throw ClosedClassrooms::exception();
        }

        return $next($request);
    }

    private static function sees(User $user, Classroom $classroom): bool
    {
        if ($user->isTeacher()) {
            return $classroom->teacher_id === $user->id && $classroom->school_id === $user->school_id;
        }
        if ($user->isStudent()) {
            return $classroom->students()->whereKey($user->id)->exists();
        }

        return false;
    }
}
