<?php

namespace Database\Seeders;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\ClassroomJoiner;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Gradebook\GradebookPublisher;
use App\Domain\Gradebook\GradebookScores;
use App\Domain\Gradebook\GradebookSettings;
use App\Domain\Grading\ReviewPriority;
use App\Domain\Review\Publisher;
use App\Domain\Students\CredentialIssuer;
use App\Domain\Students\StudentUsernames;
use App\Domain\Teachers\Workspaces;
use App\Models\Assignment;
use App\Models\ClassPost;
use App\Models\Classroom;
use App\Models\ClassroomTeacher;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\Question;
use App\Models\Response;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\TimetableSlot;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Console\View\Components\Warn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A ready-to-try standalone world (DESIGN §29.13.5): the system admin
 * (AdminSeeder), two approved teachers in workspaces of their own, one
 * classroom with ten fictional students who share one password, plus a
 * course with content to look at: one homework with published results, one
 * waiting in the review queue, a final exam graded by hand and a gradebook
 * with published grades. The second teacher co-teaches that classroom with
 * a science course and a timetable period, and owns a room of their own
 * where student 1 also sits. The answers are rows written here, not scans:
 * they carry no picture.
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * The teachers' login and the students' password come from DEMO_TEACHER_EMAIL,
 * DEMO_TEACHER2_EMAIL (default: the first with `+co`), DEMO_TEACHER_PASSWORD
 * and DEMO_STUDENT_PIN in .env (config/eduvision.php):
 * this repo is public, so no password lives in the code. When one of them is
 * missing the seeder creates no teacher and no student and prints a WARN
 * line. Re-running resets the teacher's password and the students' PIN to the
 * configured values and adds nothing twice (the course content is created
 * once, with the course).
 */
class DemoSeeder extends Seeder
{
    public const CLASSROOM_NAME = 'ป.5/1 (ตัวอย่าง)';

    public const TEACHER_NAME = 'ครูตัวอย่าง';

    public const TEACHER2_NAME = 'ครูวิทย์ตัวอย่าง';

    public const CLASSROOM2_NAME = 'ป.6/1 (ตัวอย่าง)';

    public const COURSE2_CODE = 'ว15101';

    /** The school name both demo teachers type (their workspaces are separate rows). */
    public const SCHOOL_NAME = 'โรงเรียนสาธิต Krucheck';

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

    public const COURSE_CODE = 'ค15101';

    /** Share of full marks per question, one row per student number (4 questions each). */
    private const PUBLISHED_RATIOS = [
        [1, 1, 1, 1], [1, 1, 1, 0.5], [1, 0.5, 1, 0.5], [1, 1, 0.5, 0], [0.5, 1, 0.5, 0.5],
        [1, 0, 1, 0.5], [0.5, 0.5, 0.5, 0], [1, 1, 1, 1], [0, 0.5, 0.5, 0], [1, 1, 0.5, 0.5],
    ];

    /** The final exam (out of 30) of each student number. */
    private const FINAL_SCORES = [28, 26, 24, 21, 19, 22, 14, 29, 11, 25];

    public function run(StudentEnroller $enroller, Publisher $publisher): void
    {
        $this->call(AdminSeeder::class);

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
        $email2 = trim((string) config('eduvision.demo.teacher2_email'));
        if ($email2 === '') {
            $email2 = (string) preg_replace('/@/', '+co@', $email, 1);
        }

        $classroom = DB::transaction(function () use ($email, $password, $pin, $enroller) {
            $teacher = $this->teacher($email, self::TEACHER_NAME, $password);

            $classroom = Classroom::query()->firstOrCreate(
                ['teacher_id' => $teacher->id, 'name' => self::CLASSROOM_NAME],
                [
                    'school_id' => $teacher->school_id,
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

            // One known password (DEMO_STUDENT_PIN) for the whole demo class, and no lockout left over.
            foreach ($classroom->students()->get() as $student) {
                // demo01 ... demo10 while nobody else has the name.
                $username = sprintf('demo%02d', $student->pivot->student_number);
                if ($student->username !== $username && ! StudentUsernames::taken($username)) {
                    $student->forceFill(['username' => $username])->save();
                }
                $student->credential()->update([
                    'pin_hash' => CredentialIssuer::hashPin($pin),
                    'failed_pin_attempts' => 0,
                    'locked_until' => null,
                    'must_change_password' => false,
                ]);
            }

            return $classroom;
        });

        $teacher = User::query()->findOrFail($classroom->teacher_id);
        $created = $this->content($teacher, $classroom, $publisher);
        $colleague = $this->teacher($email2, self::TEACHER2_NAME, $password);
        $this->coTeaching($teacher, $colleague, $classroom);

        $count = $classroom->students()->count();
        $this->command?->info("DemoSeeder: teacher {$email} ready; co-teacher {$email2} (same password) teaches ".self::COURSE2_CODE." in {$classroom->name} and owns ".self::CLASSROOM2_NAME);
        $this->command?->info('DemoSeeder: course '.self::COURSE_CODE.($created ? ' created with homework, an exam and published grades' : ' already there, content left as it is'));
        $this->command?->info("DemoSeeder: classroom {$classroom->name}, class code {$classroom->class_code}, join link ".ClassroomJoiner::url($classroom).", students 1-{$count} (password = DEMO_STUDENT_PIN), usernames: ".$classroom->students()->pluck('username')->implode(', '));
    }

    /**
     * An approved teacher in a workspace of their own (DESIGN §29.13.1); a
     * re-run resets the name, the password and the status.
     */
    private function teacher(string $email, string $name, string $password): User
    {
        $teacher = User::query()->where('email', $email)->first();
        if ($teacher === null) {
            $school = Workspaces::create($name, self::SCHOOL_NAME);

            return User::create([
                'school_id' => $school->id,
                'school_name' => self::SCHOOL_NAME,
                'role' => User::ROLE_TEACHER,
                'name' => $name,
                'email' => $email,
                'password' => $password, // hashed by the model cast
                'status' => User::STATUS_ACTIVE,
            ]);
        }
        $teacher->forceFill(['name' => $name, 'password' => $password, 'status' => User::STATUS_ACTIVE])->save();

        return $teacher;
    }

    /**
     * Co-teaching (DESIGN §29.13.4) and a pupil in two teachers' rooms
     * (§29.2), once: the colleague accepted an invitation to the demo
     * classroom, teaches science there with a timetable period and owns a
     * room where student 1 also sits; the maths course gets its term dates
     * and two periods, and the classroom a welcome post (§30).
     */
    private function coTeaching(User $teacher, User $colleague, Classroom $classroom): void
    {
        ClassroomTeacher::query()->firstOrCreate(
            ['classroom_id' => $classroom->id, 'email' => mb_strtolower((string) $colleague->email)],
            ['teacher_id' => $colleague->id, 'invited_by' => $teacher->id, 'status' => ClassroomTeacher::STATUS_ACCEPTED, 'decided_at' => now()],
        );
        $science = Subject::query()->firstOrCreate(['code' => 'ว'], ['name' => 'วิทยาศาสตร์']);
        $course2 = Course::query()->firstOrCreate(
            ['created_by' => $colleague->id, 'code' => self::COURSE2_CODE],
            [
                'school_id' => $colleague->school_id,
                'subject_id' => $science->id,
                'name' => 'วิทยาศาสตร์ ป.5',
                'grade_level' => 5,
                'semester' => 1,
                'academic_year' => $classroom->academic_year,
            ],
        );
        $classroom->courses()->syncWithoutDetaching([$course2->id]);
        TimetableSlot::query()->firstOrCreate(
            ['course_id' => $course2->id, 'classroom_id' => $classroom->id, 'weekday' => 2, 'period_no' => 3],
            ['starts_at' => '10:30', 'ends_at' => '11:20', 'created_by' => $colleague->id],
        );

        $own = Classroom::query()->firstOrCreate(
            ['teacher_id' => $colleague->id, 'name' => self::CLASSROOM2_NAME],
            [
                'school_id' => $colleague->school_id,
                'grade_level' => 6,
                'academic_year' => $classroom->academic_year,
                'class_code' => ClassCodeGenerator::unique(),
            ],
        );
        $first = $classroom->students()->orderBy('classroom_students.student_number')->first();
        if ($first !== null && ! $own->students()->whereKey($first->id)->exists()) {
            $own->students()->attach($first->id, ['student_number' => 1]);
        }

        $math = Course::query()->where('created_by', $teacher->id)->where('code', self::COURSE_CODE)->first();
        if ($math === null) {
            return;
        }
        if ($math->term_starts_on === null) {
            $year = $classroom->academic_year - 543;
            $math->forceFill(['term_starts_on' => sprintf('%d-05-16', $year), 'term_ends_on' => sprintf('%d-10-10', $year)])->save();
        }
        foreach ([[1, 1, '08:30', '09:20'], [3, 2, '09:20', '10:10']] as [$weekday, $period, $start, $end]) {
            TimetableSlot::query()->firstOrCreate(
                ['course_id' => $math->id, 'classroom_id' => $classroom->id, 'weekday' => $weekday, 'period_no' => $period],
                ['starts_at' => $start, 'ends_at' => $end, 'created_by' => $teacher->id],
            );
        }
        ClassPost::query()->firstOrCreate(
            ['classroom_id' => $classroom->id, 'title' => 'ยินดีต้อนรับสู่ Krucheck'],
            [
                'author_id' => $teacher->id,
                'course_id' => $math->id,
                'kind' => ClassPost::KIND_ANNOUNCEMENT,
                'body' => 'ห้องนี้ใช้ Krucheck ส่งการบ้านและดูผล นักเรียนเข้าห้องด้วยรหัสห้องหรือ QR ที่ครูให้ แล้วดูงานที่ต้องส่งในหน้าวิชาของฉัน',
                'audience' => ClassPost::AUDIENCE_ALL,
                'state' => ClassPost::STATE_PUBLISHED,
                'published_at' => now(),
                'send_to_google' => false,
            ],
        );
    }

    /**
     * The demo course and everything in it, once: false when the teacher
     * already has the course (a re-run never doubles the content).
     */
    private function content(User $teacher, Classroom $classroom, Publisher $publisher): bool
    {
        if (Course::query()->where('created_by', $teacher->id)->where('code', self::COURSE_CODE)->exists()) {
            return false;
        }

        $subject = Subject::query()->firstOrCreate(['code' => 'ค'], ['name' => 'คณิตศาสตร์']);
        if (! $this->indicators($subject)->exists()) {
            // A server without the curriculum yet: the sample indicators (upserted by code).
            $this->call(SkillSeeder::class);
        }
        $skills = $this->indicators($subject)->orderBy('code')->limit(3)->get();
        $students = $classroom->students()->orderBy('classroom_students.student_number')->get()->values();

        $course = DB::transaction(function () use ($teacher, $classroom, $subject, $skills) {
            $course = Course::create([
                'school_id' => $teacher->school_id,
                'created_by' => $teacher->id,
                'subject_id' => $subject->id,
                'code' => self::COURSE_CODE,
                'name' => 'คณิตศาสตร์ ป.5',
                'grade_level' => 5,
                'semester' => 1,
                'academic_year' => $classroom->academic_year,
            ]);
            $course->classrooms()->sync([$classroom->id]);
            $course->indicators()->sync($skills->modelKeys());
            GradebookSettings::applyTemplate($course, 'collect_final');

            return $course;
        });
        $categories = GradebookCategory::query()->where('course_id', $course->id)->orderBy('position')->get();

        // Homework 1: every answer reviewed, then published the way the app does it.
        $first = $this->homework($teacher, $classroom, $course, 'แบบฝึกหัด การคูณเลขสองหลัก', now()->subDays(7), $skills);
        foreach ($students as $n => $student) {
            $submission = $this->answers($first, $student, self::PUBLISHED_RATIOS[$n % count(self::PUBLISHED_RATIOS)], $teacher);
            $publisher->publishSubmission($submission, $teacher);
        }

        // Homework 2: graded, waiting for the teacher in the review queue.
        $second = $this->homework($teacher, $classroom, $course, 'แบบฝึกหัด โจทย์ปัญหาการคูณ', now()->addDays(3), $skills);
        foreach ($students as $n => $student) {
            $this->answers($second, $student, self::PUBLISHED_RATIOS[($n + 3) % count(self::PUBLISHED_RATIOS)], null);
        }

        // The final exam, graded on paper: its scores are typed into the gradebook.
        $final = Assignment::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'subject_id' => $subject->id,
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'title' => 'สอบปลายภาค',
            'strictness' => 'normal',
            'status' => Assignment::STATUS_READY,
            'due_at' => now()->subDay(),
            'mode' => Assignment::MODE_WORKSHEET,
            'kind' => Assignment::KIND_EXAM,
            'grading_method' => Assignment::GRADING_MANUAL,
            'manual_full_marks' => 30,
            'gradebook_category_id' => $categories->firstWhere('is_homework_default', false)?->id,
        ]);
        $scores = [];
        foreach ($students as $n => $student) {
            $scores[] = ['student_id' => $student->id, 'score' => self::FINAL_SCORES[$n % count(self::FINAL_SCORES)]];
        }
        GradebookScores::saveForAssignment($final, $teacher, $scores);
        GradebookPublisher::publish($course, $classroom, $teacher);

        return true;
    }

    /** Curriculum indicators of the subject for ป.5. */
    private function indicators(Subject $subject): Builder
    {
        return Skill::query()->where('subject_id', $subject->id)->where('level', Skill::LEVEL_INDICATOR)
            ->whereNull('school_id')->where('grade_level', 5);
    }

    /**
     * A homework without the app's worksheet (answers arrive as rows here),
     * its key approved, with four short-answer questions worth 10 in total.
     *
     * @param  Collection<int, Skill>  $skills
     */
    private function homework(User $teacher, Classroom $classroom, Course $course, string $title, DateTimeInterface $dueAt, Collection $skills): Assignment
    {
        $assignment = Assignment::create([
            'school_id' => $classroom->school_id,
            'classroom_id' => $classroom->id,
            'subject_id' => $course->subject_id,
            'course_id' => $course->id,
            'created_by' => $teacher->id,
            'title' => $title,
            'strictness' => 'normal',
            'status' => Assignment::STATUS_READY,
            'due_at' => $dueAt,
            'mode' => Assignment::MODE_FREEFORM,
            'accept_late' => true,
            'kind' => Assignment::KIND_HOMEWORK,
            'grading_method' => Assignment::GRADING_APP,
            'key_origin' => Assignment::KEY_TEACHER,
            'key_approved_at' => now(),
            'key_approved_by' => $teacher->id,
            'gradebook_category_id' => GradebookSettings::homeworkDefaultId($course->id),
        ]);
        $questions = [
            ['14 × 2 = ?', 28, 2],
            ['32 × 4 = ?', 128, 2],
            ['21 × 7 = ?', 147, 3],
            ['ดินสอกล่องละ 12 แท่ง มี 6 กล่อง มีดินสอทั้งหมดกี่แท่ง', 72, 3],
        ];
        foreach ($questions as $i => [$prompt, $answer, $points]) {
            $question = Question::create([
                'assignment_id' => $assignment->id,
                'position' => $i + 1,
                'type' => Question::TYPE_SHORT,
                'prompt_text' => $prompt,
                'max_points' => $points,
                'is_numeric' => true,
                'match_mode' => 'flexible',
                'answer_key' => ['accepted' => [(string) $answer], 'numeric' => ['value' => $answer, 'abs_tol' => 0]],
                'rubric_status' => Question::RUBRIC_NOT_NEEDED,
            ]);
            if ($skills->isNotEmpty()) {
                $question->skills()->attach($skills[$i % $skills->count()]->id);
            }
        }

        return $assignment;
    }

    /**
     * One student's answers with the given share of full marks per question:
     * reviewed by $reviewer, or left as the grader's proposal when null.
     *
     * @param  list<int|float>  $ratios
     */
    private function answers(Assignment $assignment, User $student, array $ratios, ?User $reviewer): Submission
    {
        $submission = Submission::create([
            'assignment_id' => $assignment->id,
            'student_id' => $student->id,
            'status' => $reviewer === null ? Submission::STATUS_NEEDS_REVIEW : Submission::STATUS_REVIEWED,
            'channel' => Submission::CHANNEL_WHOLE_PAGE,
            'submitted_at' => now()->subDay(),
        ]);
        foreach ($assignment->questions()->orderBy('position')->get() as $i => $question) {
            $ratio = (float) ($ratios[$i] ?? 1);
            $score = round((float) $question->max_points * $ratio * 2) / 2;
            $understanding = $ratio >= 1 ? 'good' : ($ratio > 0 ? 'partial' : 'not_yet');
            $errors = $ratio >= 1 ? [] : ['calculation'];
            $answer = $question->answer_key['accepted'][0];
            Response::create([
                'submission_id' => $submission->id,
                'question_id' => $question->id,
                'grading_state' => Response::STATE_SCORED,
                'extraction' => [
                    'blank' => false,
                    'suspicious_instruction' => false,
                    'legibility' => 'clear',
                    'answer_text' => $ratio >= 1 ? $answer : (string) ((int) $answer + ($ratio > 0 ? 1 : 10)),
                    'key_match' => $ratio >= 1 ? 'exact' : 'none',
                    'error_types' => $errors,
                ],
                'fuzzy_trace' => ['system' => $question->type, 'suspicious_instruction' => false],
                'ai_score' => $score,
                'ai_understanding' => $understanding,
                'ai_error_types' => $errors,
                'review_priority' => $ratio >= 1 ? 0.05 : ($ratio > 0 ? 0.6 : 0.3),
                'priority_band' => $ratio >= 1 ? ReviewPriority::BAND_CONFIDENT : ($ratio > 0 ? ReviewPriority::BAND_CHECK : ReviewPriority::BAND_LOOK),
                'explanation' => $ratio >= 1
                    ? 'ถูกต้อง ทำได้ดีมาก'
                    : 'คำตอบยังไม่ตรงกับเฉลย ลองตั้งหลักคูณทีละหลักแล้วตรวจการทดอีกครั้ง',
                'explanation_source' => Response::EXPLANATION_TEMPLATE,
                'final_score' => $reviewer === null ? null : $score,
                'final_understanding' => $reviewer === null ? null : $understanding,
                'final_error_types' => $reviewer === null ? null : $errors,
                'reviewed_by' => $reviewer?->id,
                'reviewed_at' => $reviewer === null ? null : now(),
            ]);
        }

        return $submission;
    }

    /** The Thai academic year (B.E.) that is running now: it starts in May. */
    public static function academicYear(): int
    {
        $now = now('Asia/Bangkok');

        return $now->year + 543 - ($now->month < 5 ? 1 : 0);
    }
}
