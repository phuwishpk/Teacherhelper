<?php

namespace App\Policies;

use App\Domain\Classrooms\ClassroomAccess;
use App\Models\ClassroomCourseRequest;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Course requests of shared homerooms (DESIGN §24.7, §24.8): the requester
 * and the classroom's homeroom teacher see a request; only the homeroom
 * teacher approves or declines it (403 not_homeroom_teacher for the
 * requester) and only the requester cancels it. Admins bind directly in
 * Filament (ClassroomPolicy::assignCourses).
 */
class CourseRequestPolicy
{
    public function viewAny(User $user): bool
    {
        return ClassroomAccess::isSchoolTeacher($user);
    }

    public function view(User $user, ClassroomCourseRequest $request): bool
    {
        return $this->isRequester($user, $request) || $this->isHomeroom($user, $request);
    }

    public function decide(User $user, ClassroomCourseRequest $request): Response|bool
    {
        if ($this->isHomeroom($user, $request)) {
            return true;
        }

        return $this->isRequester($user, $request) ? ClassroomAccess::denyNotHomeroom() : false;
    }

    public function cancel(User $user, ClassroomCourseRequest $request): Response|bool
    {
        if ($this->isRequester($user, $request)) {
            return true;
        }

        return $this->isHomeroom($user, $request)
            ? Response::deny('ยกเลิกได้เฉพาะครูที่ส่งคำขอ ครูประจำชั้นใช้ "ไม่อนุมัติ" แทน', 'not_requester')
            : false;
    }

    private function isRequester(User $user, ClassroomCourseRequest $request): bool
    {
        return ClassroomAccess::isSchoolTeacher($user) && $request->requested_by === $user->id;
    }

    private function isHomeroom(User $user, ClassroomCourseRequest $request): bool
    {
        $classroom = $request->classroom;

        return $classroom !== null && ClassroomAccess::homeroomOf($user, $classroom);
    }
}
