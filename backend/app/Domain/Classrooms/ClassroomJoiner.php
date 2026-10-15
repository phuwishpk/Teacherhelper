<?php

namespace App\Domain\Classrooms;

use App\Domain\Students\StudentCode;
use App\Domain\Students\StudentMerger;
use App\Domain\Web\WebApp;
use App\Exceptions\ApiException;
use App\Models\Classroom;
use App\Models\User;
use App\Models\UserGoogleIdentity;
use Illuminate\Support\Facades\DB;
use Mpdf\QrCode\QrCode;

/**
 * DESIGN §29.13.3: a pupil joins a classroom with its 6-character class
 * code (or the QR of the join link). Joining either claims a seat the
 * teacher made for them (an account nobody has used yet, merged into the
 * pupil's own account) or adds the pupil with the next free number.
 */
final class ClassroomJoiner
{
    /** student_number is TINYINT UNSIGNED. */
    public const MAX_NUMBER = 255;

    public function __construct(private readonly StudentMerger $merger) {}

    /**
     * The classroom behind a join code (closed ones included, so the page can
     * say so), else 404 classroom_not_found.
     *
     * @throws ApiException
     */
    public static function byCode(string $code): Classroom
    {
        $normalized = ClassCodeGenerator::normalize($code);
        $classroom = strlen($normalized) === ClassCodeGenerator::LENGTH
            ? Classroom::query()->with('teacher:id,name,school_name')->where('class_code', $normalized)->first()
            : null;
        if ($classroom === null) {
            throw new ApiException('ไม่พบห้องเรียนของรหัสนี้ ตรวจรหัสห้องกับครูอีกครั้ง', 'classroom_not_found', 404);
        }

        return $classroom;
    }

    /** Whether the classroom takes new members right now. */
    public static function accepts(Classroom $classroom): bool
    {
        return ! $classroom->isClosed() && (bool) $classroom->join_open;
    }

    /** The join link of the web app: `https://<host>/app/#/join/<code>`. */
    public static function url(Classroom $classroom): string
    {
        return WebApp::url('join/'.$classroom->class_code);
    }

    /**
     * GET /join/{code}: what anybody holding the code may know (no roster).
     *
     * @return array<string, mixed>
     */
    public static function publicInfo(Classroom $classroom): array
    {
        $classroom->loadMissing('teacher:id,name,school_name');

        return [
            'name' => $classroom->name,
            'grade_level' => $classroom->grade_level,
            'academic_year' => $classroom->academic_year,
            'teacher_name' => $classroom->teacher?->name,
            'school_name' => $classroom->teacher?->school_name,
            'open' => self::accepts($classroom),
        ];
    }

    /**
     * GET /student/join/{code}: the classroom, whether the pupil is in it
     * already, and the seats still free to claim.
     *
     * @return array<string, mixed>
     */
    public function preview(User $student, Classroom $classroom): array
    {
        $member = $classroom->students()->whereKey($student->id)->exists();

        return [
            ...self::publicInfo($classroom),
            'member' => $member,
            'seats' => $member ? [] : $this->freeSeats($classroom),
        ];
    }

    /**
     * The roster accounts nobody has used yet: made by the teacher, never
     * signed in (still on the initial password) and without a Google account.
     *
     * @return list<array{student_id: int, student_number: int, name: string, needs_code: bool}>
     */
    public function freeSeats(Classroom $classroom): array
    {
        return $classroom->students()
            ->where('users.status', User::STATUS_ACTIVE)
            ->whereNull('users.deleted_at')
            ->whereNull('users.merged_into_id')
            ->whereHas('credential', fn ($q) => $q->where('must_change_password', true))
            ->whereDoesntHave('googleIdentity')
            ->get(['users.id', 'users.name', 'users.student_code'])
            ->map(fn (User $seat) => [
                'student_id' => $seat->id,
                'student_number' => (int) $seat->pivot->student_number,
                'name' => $seat->name,
                'needs_code' => $seat->student_code !== null,
            ])
            ->values()
            ->all();
    }

    /**
     * POST /student/join {code, student_id?, student_code?}.
     *
     * @return array{member: bool, joined: bool, student_number: int|null}
     *
     * @throws ApiException 409 join_closed / seat_taken, 422 student_code_mismatch / classroom_full
     */
    public function join(User $student, Classroom $classroom, ?int $seatId, ?string $studentCode): array
    {
        $current = $classroom->students()->whereKey($student->id)->first();
        if ($current !== null) {
            return ['member' => true, 'joined' => false, 'student_number' => (int) $current->pivot->student_number];
        }
        if (! self::accepts($classroom)) {
            throw new ApiException('ห้องนี้ปิดรับสมาชิกแล้ว ติดต่อครูประจำวิชาเพื่อให้เพิ่มชื่อ', 'join_closed', 409);
        }
        if ($seatId !== null) {
            return $this->claimSeat($student, $classroom, $seatId, $studentCode);
        }

        return DB::transaction(function () use ($student, $classroom) {
            Classroom::query()->whereKey($classroom->id)->lockForUpdate()->first();
            $max = (int) DB::table('classroom_students')->where('classroom_id', $classroom->id)->max('student_number');
            if ($max >= self::MAX_NUMBER) {
                throw new ApiException('ห้องนี้เต็มแล้ว (เลขที่ถึง '.self::MAX_NUMBER.')', 'classroom_full', 422);
            }
            $classroom->students()->attach($student->id, ['student_number' => $max + 1]);

            return ['member' => true, 'joined' => true, 'student_number' => $max + 1];
        });
    }

    /**
     * "ย้ายไปที่นั่งนี้" (§29.2): the seat's account is merged into the
     * pupil's own (§24.5), so the number, the student code and anything the
     * teacher already attached to the seat follow. An account made by Google
     * sign-up takes the name the teacher typed.
     *
     * @return array{member: bool, joined: bool, student_number: int|null}
     */
    private function claimSeat(User $student, Classroom $classroom, int $seatId, ?string $studentCode): array
    {
        $seat = collect($this->freeSeats($classroom))->firstWhere('student_id', $seatId);
        if ($seat === null) {
            throw new ApiException('ที่นั่งนี้มีคนใช้แล้ว เลือกชื่ออื่นหรือเข้าด้วยชื่อตัวเอง', 'seat_taken', 409);
        }
        $seatUser = User::query()->findOrFail($seatId);
        if ($seat['needs_code']) {
            $given = $studentCode === null ? null : StudentCode::normalize($studentCode);
            if ($given === null || $given !== $seatUser->student_code) {
                $message = 'เลขประจำตัวไม่ตรงกับที่ครูกรอกไว้';

                throw new ApiException($message, 'student_code_mismatch', 422, ['student_code' => [$message]]);
            }
        }
        $adoptName = $student->googleIdentity()->where('linked_via', UserGoogleIdentity::VIA_GOOGLE_SIGNUP)->exists();

        $this->merger->merge($student, $seatUser, $student);
        if ($adoptName) {
            $student->forceFill(['name' => $seatUser->name])->save();
        }

        return ['member' => true, 'joined' => true, 'student_number' => $seat['student_number']];
    }

    /**
     * GET /classrooms/{id}/join-qr: the QR of the join link as rows of
     * '1' (dark) and '0', without the quiet zone (the app draws it).
     *
     * @return array{code: string, url: string, matrix: list<string>}
     */
    public static function qr(Classroom $classroom): array
    {
        $url = self::url($classroom);
        $qr = new QrCode($url, 'M');
        $qr->disableBorder();
        $size = $qr->getQrSize();
        $final = $qr->getFinal();
        $rows = [];
        for ($row = 4; $row < $size - 4; $row++) {
            $line = '';
            for ($column = 4; $column < $size - 4; $column++) {
                $line .= $final[$column + $row * $size + 1] ? '1' : '0';
            }
            $rows[] = $line;
        }

        return ['code' => $classroom->class_code, 'url' => $url, 'matrix' => $rows];
    }
}
