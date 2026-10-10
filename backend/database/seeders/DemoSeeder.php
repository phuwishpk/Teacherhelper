<?php

namespace Database\Seeders;

use App\Domain\Classrooms\ClassCodeGenerator;
use App\Domain\Classrooms\StudentEnroller;
use App\Domain\Gradebook\GradebookPublisher;
use App\Domain\Gradebook\GradebookScores;
use App\Domain\Gradebook\GradebookSettings;
use App\Domain\Grading\ReviewPriority;
use App\Domain\Review\Publisher;
use App\Domain\Students\CredentialIssuer;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\Course;
use App\Models\GradebookCategory;
use App\Models\Question;
use App\Models\Response;
use App\Models\School;
use App\Models\Skill;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\User;
use DateTimeInterface;
use Illuminate\Console\View\Components\Warn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * A ready-to-try set of accounts: the school and the system admin
 * (SchoolSeeder, AdminSeeder), one approved teacher and one classroom with
 * ten fictional students who share one PIN, plus a course with content to
 * look at: one homework with published results, one waiting in the review
 * queue, a final exam graded by hand and a gradebook with published grades.
 * The answers are rows written here, not scans: they carry no picture.
 *
 *   php artisan db:seed --class=DemoSeeder --force
 *
 * The teacher's login and the students' PIN come from DEMO_TEACHER_EMAIL,
 * DEMO_TEACHER_PASSWORD and DEMO_STUDENT_PIN in .env (config/eduvision.php):
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

        $teacher = User::query()->findOrFail($classroom->teacher_id);
        $created = $this->content($teacher, $classroom, $publisher);

        $count = $classroom->students()->count();
        $this->command?->info("DemoSeeder: teacher {$email} ready");
        $this->command?->info('DemoSeeder: course '.self::COURSE_CODE.($created ? ' created with homework, an exam and published grades' : ' already there, content left as it is'));
        $this->command?->info("DemoSeeder: classroom {$classroom->name}, class code {$classroom->class_code}, students 1-{$count} (PIN = DEMO_STUDENT_PIN)");
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
