<?php

namespace Database\Seeders;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Students\CredentialIssuer;
use App\Models\Classroom;
use App\Models\School;
use App\Models\User;
use Illuminate\Console\View\Components\Warn;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A ready-to-try set of accounts: the school and the system admin
 * (SchoolSeeder, AdminSeeder), one approved teacher and one classroom with
 * ten fictional students who share one PIN.
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * The teacher's login and the students' PIN come from DEMO_TEACHER_EMAIL,
 * DEMO_TEACHER_PASSWORD and DEMO_STUDENT_PIN in .env (config/eduvision.php):
 * this repo is public, so no password lives in the code. When one of them is
 * missing the seeder creates no teacher and no student and prints a WARN
 * line. Re-running resets the teacher's password and the students' PIN to the
 * configured values and adds nothing twice.
 */
class DemoSeeder extends Seeder
{
    public const CLASSROOM_NAME = 'ป.5/1 (ตัวอย่าง)';

    public const TEACHER_NAME = 'ครูตัวอย่าง';

    /** Fictional names, in the order of their student numbers. */
    public const STUDENTS = [
        'ด.ช. กล้าหาญ ใจดี',
        'ด.ญ. ขวัญใจ มีสุข',
        'ด.ช. คิมหันต์ ทองดี',
        'ด.ญ. ชนิดา แก้วใส',
        'ด.ช. ณัฐพล รุ่งเรือง',
        'ด.ญ. ดารุณี ศรีสุข',
        'ด.ช. ธนากร พึ่งบุญ',
        'ด.ญ. นภัสสร วงศ์ดี',
        'ด.ช. ปกรณ์ รักเรียน',
        'ด.ญ. พิมพ์ชนก สายทอง',
    ];

    public function run(StudentEnroller $enroller): void
    {
        $this->call([SchoolSeeder::class, AdminSeeder::class]);

        $email = trim((string) config('eduvision.demo.teacher_email'));
        $password = (string) config('eduvision.demo.teacher_password');
        $pin = (string) config('eduvision.demo.student_pin');

        if ($email === '' || $password === '' || preg_match('/^\d{6}$/', $pin) !== 1) {
            if ($this->command !== null) {
                (new Warn($this->command->getOutput()))->render(
                    'DemoSeeder: DEMO_TEACHER_EMAIL, DEMO_TEACHER_PASSWORD and DEMO_STUDENT_PIN (6 digits) '
                    .'are not all set in .env, so no demo teacher or student was created.'
                );
            }

            return;
        }

        $school = School::query()
            ->where('teacher_join_code', config('eduvision.seed_teacher_join_code'))
            ->firstOrFail();

        $classroom = DB::transaction(function () use ($school, $email, $password, $pin, $enroller) {
            $teacher = User::query()->updateOrCreate(
                ['email' => $email],
                [
                    'school_id' => $school->id,
                    'role' => User::ROLE_TEACHER,
                    'name' => self::TEACHER_NAME,
                    'password' => $password, // hashed by the model cast
                    'status' => User::STATUS_ACTIVE,
                ],
            );

            $classroom = Classroom::query()->firstOrCreate(
                ['teacher_id' => $teacher->id, 'name' => self::CLASSROOM_NAME],
                [
                    'school_id' => $school->id,
                    'grade_level' => 5,
                    'academic_year' => self::academicYear(),
                    'class_code' => ClassCodeGenerator::unique(),
                ],
            );

            if (! $classroom->students()->exists()) {
                $rows = [];
                foreach (self::STUDENTS as $i => $name) {
                    $rows[] = ['name' => $name, 'student_number' => $i + 1];
                }
                $enroller->enroll($classroom, $rows);
            }

            // One known PIN for the whole demo class, and no lockout left over.
            foreach ($classroom->students()->get() as $student) {
                $student->credential()->update([
                    'pin_hash' => CredentialIssuer::hashPin($pin),
                    'failed_pin_attempts' => 0,
                    'locked_until' => null,
                ]);
            }

            return $classroom;
        });

        $count = $classroom->students()->count();
        $this->command?->info("DemoSeeder: teacher {$email} ready");
        $this->command?->info("DemoSeeder: classroom {$classroom->name}, class code {$classroom->class_code}, students 1-{$count} (PIN = DEMO_STUDENT_PIN)");
    }

    /** The Thai academic year (B.E.) that is running now: it starts in May. */
    public static function academicYear(): int
    {
        $now = now('Asia/Bangkok');

        return $now->year + 543 - ($now->month < 5 ? 1 : 0);
    }
}
