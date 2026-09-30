<?php

namespace Tests\Feature\Security;

use App\Domain\Google\GoogleScopes;
use App\Domain\Scans\ScanFiles;
use App\Domain\Worksheets\QrSigner;
use App\Models\Appeal;
use App\Models\Assignment;
use App\Models\AssignmentGoogleLink;
use App\Models\Classroom;
use App\Models\ClassroomGoogleLink;
use App\Models\ClassroomSubmissionImport;
use App\Models\Course;
use App\Models\DocumentExtraction;
use App\Models\ExamSection;
use App\Models\GoogleAccount;
use App\Models\GradeConflict;
use App\Models\Layout;
use App\Models\LearningResource;
use App\Models\LessonPlan;
use App\Models\LoginCardPrint;
use App\Models\ModelVersion;
use App\Models\PracticeItem;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\Scan;
use App\Models\School;
use App\Models\Skill;
use App\Models\StudentAnalysis;
use App\Models\Subject;
use App\Models\Submission;
use App\Models\SubmissionPage;
use App\Models\Unit;
use App\Models\User;
use App\Models\WorksheetPrint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Two schools with one resource of every kind owned by teacher A of school A
 * (DESIGN §16.1 (a)): the security suite lets every other actor try to reach
 * them. Everything sits on the fake local disk so file routes answer 200 for
 * the owner, which proves the denials are not "file missing" by accident.
 */
trait SecurityWorld
{
    protected School $schoolA;

    protected School $schoolB;

    /** Owner of every resource below. */
    protected User $teacherA;

    /** Same school, does not teach the classroom. */
    protected User $teacherA2;

    /** Another school. */
    protected User $teacherB;

    /** A disabled teacher of school A (was the owner's colleague). */
    protected User $disabledTeacherA;

    protected User $admin;

    /** In teacher A's classroom, result not published (needs review). */
    protected User $studentA;

    /** Classmate of student A with a published result. */
    protected User $studentA2;

    /** A student of school B. */
    protected User $studentB;

    protected Classroom $classroomA;

    protected StudentAnalysis $analysisA;

    protected Classroom $classroomB;

    protected Subject $subject;

    /** A sub-skill of school A (school-scoped, DESIGN §2.3). */
    protected Skill $skillA;

    /** A curriculum skill every school sees. */
    protected Skill $curriculumSkill;

    /** An indicator teacher A added for school A (DESIGN §20.2, "ครูเพิ่มเอง"). */
    protected Skill $teacherSkillA;

    protected Assignment $assignmentA;

    /** An exam of classroom A (DESIGN §22) with one mcq section, one question and images. */
    protected Assignment $examA;

    protected ExamSection $examSectionA;

    protected Question $examQuestionA;

    protected QuestionOption $examOptionA;

    protected Question $shortA;

    protected Question $openA;

    protected WorksheetPrint $printA;

    protected LoginCardPrint $cardPrintA;

    protected Submission $submissionA;

    protected Submission $submissionA2;

    protected Scan $scanA;

    protected Scan $scanA2;

    protected Response $responseA;

    protected Response $responseA2;

    protected Appeal $appealA2;

    protected PracticeItem $practiceItemA;

    protected LearningResource $resourceA;

    protected ModelVersion $model;

    protected ClassroomSubmissionImport $importA;

    protected GradeConflict $conflictA;

    /** What Gemini read from a document of school A (DESIGN §19.5). */
    protected DocumentExtraction $extractionA;

    /** A whole-page file of student A's (unpublished) submission (DESIGN §19.4). */
    protected SubmissionPage $pageA;

    /** Teacher A's course bound to classroom A, with a unit and a lesson plan (DESIGN §20.1). */
    protected Course $courseA;

    protected Unit $unitA;

    protected LessonPlan $lessonPlanA;

    protected function makeSecurityWorld(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');

        $this->schoolA = $this->makeSchool(['name' => 'โรงเรียน ก']);
        $this->schoolB = $this->makeSchool(['name' => 'โรงเรียน ข']);
        $this->teacherA = $this->makeTeacher($this->schoolA, ['name' => 'ครูเจ้าของ']);
        $this->teacherA2 = $this->makeTeacher($this->schoolA, ['name' => 'ครูร่วมโรงเรียน']);
        $this->teacherB = $this->makeTeacher($this->schoolB, ['name' => 'ครูโรงเรียนอื่น']);
        $this->disabledTeacherA = User::factory()->teacher($this->schoolA)->disabled()->create();
        $this->admin = $this->makeAdmin();

        $this->classroomA = $this->makeClassroom($this->teacherA, ['name' => 'ป.4/1']);
        $this->classroomB = $this->makeClassroom($this->teacherB, ['name' => 'ป.4/9']);
        $this->studentA = $this->enrollStudent($this->classroomA, 1, 'นักเรียน ก หนึ่ง')['student'];
        $this->studentA2 = $this->enrollStudent($this->classroomA, 2, 'นักเรียน ก สอง')['student'];
        $this->studentB = $this->enrollStudent($this->classroomB, 1, 'นักเรียน ข')['student'];

        $this->subject = Subject::factory()->create(['code' => 'ค', 'name' => 'คณิตศาสตร์']);
        $this->curriculumSkill = Skill::factory()->create(['subject_id' => $this->subject->id, 'code' => 'ค 1.1 ป.4/1', 'name' => 'จำนวนนับ']);
        $this->skillA = Skill::factory()->create(['subject_id' => $this->subject->id, 'school_id' => $this->schoolA->id, 'parent_id' => $this->curriculumSkill->id, 'code' => 'ค 1.1 ป.4/1-ก', 'name' => 'ทักษะย่อยของโรงเรียน ก', 'level' => Skill::LEVEL_SUB_INDICATOR, 'source' => Skill::SOURCE_SCHOOL_ADMIN]);
        $this->teacherSkillA = Skill::factory()->create([
            'subject_id' => $this->subject->id, 'school_id' => $this->schoolA->id, 'parent_id' => $this->curriculumSkill->id,
            'code' => 'ค 1.1 ป.4/1/ค1', 'name' => 'ครู ก เพิ่มเอง', 'level' => Skill::LEVEL_SUB_INDICATOR,
            'source' => Skill::SOURCE_TEACHER, 'created_by' => $this->teacherA->id,
        ]);

        $this->assignmentA = Assignment::factory()->for_classroom($this->classroomA)->create([
            'subject_id' => $this->subject->id,
            'status' => Assignment::STATUS_READY,
            'current_layout_version' => 1,
        ]);
        $this->shortA = Question::factory()->short()->create(['assignment_id' => $this->assignmentA->id, 'position' => 1]);
        $this->openA = Question::factory()->open()->create(['assignment_id' => $this->assignmentA->id, 'position' => 2]);
        $this->shortA->skills()->attach($this->skillA->id);
        Layout::create([
            'assignment_id' => $this->assignmentA->id,
            'version' => 1,
            'pages' => [[
                'assignment_id' => $this->assignmentA->id, 'version' => 1, 'page' => 1, 'page_count' => 1,
                'marker' => ['dictionary' => 'DICT_4X4_50', 'ids' => [0, 1, 2, 3], 'size_mm' => 12],
                'frame_mm' => ['x' => 16, 'y' => 16, 'w' => 178, 'h' => 265],
                'regions' => [
                    ['region_id' => 'q'.$this->shortA->id, 'question_id' => $this->shortA->id, 'kind' => 'box', 'numeric' => true, 'rect' => ['x' => 0.55, 'y' => 0.27, 'w' => 0.3, 'h' => 0.05]],
                    ['region_id' => 'q'.$this->openA->id, 'question_id' => $this->openA->id, 'kind' => 'lines', 'line_count' => 4, 'rect' => ['x' => 0.06, 'y' => 0.4, 'w' => 0.88, 'h' => 0.2]],
                ],
            ]],
        ]);

        $this->printA = WorksheetPrint::create(['assignment_id' => $this->assignmentA->id, 'layout_version' => 1, 'requested_by' => $this->teacherA->id, 'status' => WorksheetPrint::STATUS_READY, 'file_path' => "worksheets/{$this->assignmentA->id}/print.pdf"]);
        $disk->put($this->printA->file_path, '%PDF-1.4 fake');
        $this->cardPrintA = LoginCardPrint::create(['school_id' => $this->schoolA->id, 'classroom_id' => $this->classroomA->id, 'requested_by' => $this->teacherA->id, 'status' => LoginCardPrint::STATUS_READY, 'file_path' => 'login-cards/cards.pdf']);
        $disk->put($this->cardPrintA->file_path, '%PDF-1.4 fake');

        // Student A: scanned, graded, waiting for the teacher. Student A2: published.
        [$this->submissionA, $this->scanA, $this->responseA] = $this->scannedAnswer($this->studentA, Submission::STATUS_NEEDS_REVIEW);
        [$this->submissionA2, $this->scanA2, $this->responseA2] = $this->scannedAnswer($this->studentA2, Submission::STATUS_PUBLISHED);
        $this->responseA2->forceFill(['final_score' => 1.0, 'final_understanding' => 'partial', 'final_error_types' => ['calculation'], 'reviewed_by' => $this->teacherA->id, 'reviewed_at' => now()])->save();
        $this->submissionA2->forceFill(['published_at' => now(), 'published_by' => $this->teacherA->id, 'total_score' => 1.0])->save();
        $this->appealA2 = Appeal::create(['response_id' => $this->responseA2->id, 'student_id' => $this->studentA2->id, 'reason' => 'ขอตรวจใหม่']);
        $this->pageA = SubmissionPage::create([
            'submission_id' => $this->submissionA->id, 'source' => SubmissionPage::SOURCE_CLASSROOM, 'position' => 1,
            'mime_type' => 'image/webp', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'state' => SubmissionPage::STATE_GRADED, 'received_at' => now(),
        ]);
        $this->pageA->forceFill(['file_path' => "pages/{$this->schoolA->id}/{$this->assignmentA->id}/{$this->pageA->id}.webp"])->save();
        $disk->put($this->pageA->file_path, $this->scanFixture('page.webp'));

        $this->extractionA = DocumentExtraction::create([
            'school_id' => $this->schoolA->id, 'input_hash' => str_repeat('b', 64), 'purpose' => DocumentExtraction::PURPOSE_ANSWER_KEY,
            'status' => DocumentExtraction::STATUS_DONE, 'requested_by' => $this->teacherA->id,
            'result' => ['kind' => 'answer_key_read', 'notes_th' => '', 'questions' => []],
        ]);

        $this->courseA = Course::create([
            'school_id' => $this->schoolA->id, 'created_by' => $this->teacherA->id, 'subject_id' => $this->subject->id,
            'code' => 'ค14101', 'name' => 'คณิตศาสตร์ 4', 'grade_level' => 4, 'academic_year' => 2569,
        ]);
        $this->courseA->classrooms()->attach($this->classroomA->id);
        $this->courseA->indicators()->attach($this->curriculumSkill->id);
        $this->unitA = Unit::create(['course_id' => $this->courseA->id, 'position' => 1, 'title' => 'จำนวนนับ']);
        $this->lessonPlanA = LessonPlan::create(['course_id' => $this->courseA->id, 'unit_id' => $this->unitA->id, 'position' => 1, 'title' => 'การอ่านจำนวน']);

        $this->practiceItemA = PracticeItem::create([
            'school_id' => $this->schoolA->id, 'skill_id' => $this->skillA->id, 'answer_type' => 'numeric',
            'prompt_text' => '3 + 4 = ?', 'options' => null,
            'answer_key' => ['accepted' => ['7'], 'numeric' => ['value' => 7, 'abs_tol' => 0]],
            'explanation' => '3 บวก 4 ได้ 7', 'status' => PracticeItem::STATUS_APPROVED, 'source' => 'teacher',
            'approved_by' => $this->teacherA->id, 'approved_at' => now(),
        ]);
        $this->resourceA = LearningResource::create(['school_id' => $this->schoolA->id, 'skill_id' => $this->skillA->id, 'title' => 'วิดีโอทบทวน', 'url' => 'https://example.com/review', 'added_by' => $this->teacherA->id]);

        $this->model = ModelVersion::create(['name' => 'digit_crnn', 'version' => '0.1.0', 'file_path' => 'models/digit_crnn/0.1.0.tflite', 'sha256' => hash('sha256', 'tflite-bytes'), 'metrics' => ['cer' => 0.05], 'is_active' => true]);
        $disk->put($this->model->file_path, 'tflite-bytes');

        // Google Classroom (§18): teacher A connected, classroom and assignment posted.
        GoogleAccount::create([
            'user_id' => $this->teacherA->id, 'google_sub' => 'sub-a', 'email' => 'teacher-a@school.example',
            'encrypted_refresh_token' => 'refresh-token-not-real', 'scopes' => implode(' ', GoogleScopes::REQUIRED), 'connected_at' => now(),
        ]);
        ClassroomGoogleLink::create(['classroom_id' => $this->classroomA->id, 'course_id' => 'course-a', 'course_name' => 'EduVision ทดสอบ', 'owner_user_id' => $this->teacherA->id, 'linked_at' => now()]);
        AssignmentGoogleLink::create(['assignment_id' => $this->assignmentA->id, 'course_work_id' => 'cw-a', 'alternate_link' => 'https://classroom.google.com/c/a', 'drive_file_id' => null, 'posted_by' => $this->teacherA->id, 'posted_at' => now()]);
        $this->importA = ClassroomSubmissionImport::create([
            'assignment_id' => $this->assignmentA->id, 'google_submission_id' => 'sub-1', 'google_user_id' => 'guser-1',
            'student_id' => $this->studentA->id, 'state' => ClassroomSubmissionImport::STATE_NEW, 'google_update_time' => now(),
            'attachments' => [['drive_file_id' => 'f1', 'title' => 'page.jpg', 'mime_type' => 'image/jpeg']],
        ]);
        $this->conflictA = GradeConflict::create([
            'submission_id' => $this->submissionA->id, 'import_id' => $this->importA->id,
            'app_score' => 5, 'classroom_score' => 4, 'detected_at' => now(),
        ]);

        // An exam of classroom A with a figure on its question and on its first option (§22.15).
        $this->examA = Assignment::factory()->for_classroom($this->classroomA)->create([
            'subject_id' => $this->subject->id, 'course_id' => $this->courseA->id, 'kind' => Assignment::KIND_EXAM,
            'grading_method' => Assignment::GRADING_APP, 'due_at' => now()->addWeek(), 'title' => 'สอบกลางภาค',
        ]);
        $this->examSectionA = ExamSection::create(['assignment_id' => $this->examA->id, 'position' => 1, 'type' => ExamSection::TYPE_MCQ, 'option_count' => 4]);
        $this->examQuestionA = Question::create([
            'assignment_id' => $this->examA->id, 'section_id' => $this->examSectionA->id, 'position' => 1, 'type' => Question::TYPE_MCQ,
            'prompt_text' => '2 + 2 = ?', 'max_points' => 1, 'answer_key' => ['accepted_options' => [2]],
            'origin' => Question::ORIGIN_TEACHER, 'approved_at' => now(),
        ]);
        foreach (['3', '4', '5', '6'] as $i => $text) {
            $option = QuestionOption::create(['question_id' => $this->examQuestionA->id, 'position' => $i + 1, 'text' => $text]);
            $this->examOptionA ??= $option;
        }
        $this->examQuestionA->forceFill(['prompt_image_path' => "exams/{$this->schoolA->id}/{$this->examA->id}/figures/q{$this->examQuestionA->id}.jpg"])->save();
        $this->examOptionA->forceFill(['image_path' => "exams/{$this->schoolA->id}/{$this->examA->id}/figures/o{$this->examOptionA->id}.jpg"])->save();
        $disk->put($this->examQuestionA->prompt_image_path, 'jpeg-bytes');
        $disk->put($this->examOptionA->image_path, 'jpeg-bytes');

        // The analysis of student A in classroom A (§20.5), with a draft for the student.
        $this->analysisA = StudentAnalysis::create([
            'student_id' => $this->studentA->id, 'classroom_id' => $this->classroomA->id,
            'computed_input_hash' => str_repeat('c', 64), 'strengths' => [], 'areas' => [],
            'status' => StudentAnalysis::STATUS_DRAFTED, 'teacher_text' => 'ครู', 'student_text' => 'นักเรียน',
        ]);
    }

    /**
     * One scanned page of assignment A with an AI-scored short answer.
     *
     * @return array{0: Submission, 1: Scan, 2: Response}
     */
    private function scannedAnswer(User $student, string $status): array
    {
        $submission = Submission::create(['assignment_id' => $this->assignmentA->id, 'student_id' => $student->id, 'status' => $status]);
        $scan = Scan::create([
            'client_scan_id' => (string) Str::uuid(), 'submission_id' => $submission->id, 'page_no' => 1, 'layout_version' => 1,
            'uploaded_by' => $this->teacherA->id, 'scanned_at' => now(), 'blur_score' => 150.0, 'state' => Scan::STATE_ACTIVE,
            'page_image_path' => ScanFiles::pagePath($this->schoolA->id, $this->assignmentA->id, 0),
        ]);
        $scan->forceFill(['page_image_path' => ScanFiles::pagePath($this->schoolA->id, $this->assignmentA->id, $scan->id)])->save();
        Storage::disk('local')->put($scan->page_image_path, $this->scanFixture('page.webp'));
        $response = Response::create([
            'submission_id' => $submission->id, 'question_id' => $this->shortA->id, 'scan_id' => $scan->id,
            'crop_path' => ScanFiles::cropPath($this->schoolA->id, $this->assignmentA->id, 0),
            'grading_state' => Response::STATE_SCORED, 'ai_score' => 1.0, 'ai_understanding' => 'partial', 'ai_error_types' => ['calculation'],
            'review_priority' => 0.3, 'priority_band' => 'look', 'fuzzy_trace' => ['system' => 'short', 'suspicious_instruction' => false],
            'extraction' => ['blank' => false, 'suspicious_instruction' => false, 'legibility' => 'clear', 'answer_text' => '21', 'key_match' => 'different', 'error_types' => ['calculation'], 'summary_th' => 'คำนวณพลาด'],
            'explanation' => 'ลองตรวจการคำนวณอีกครั้ง', 'ink_ratio' => 0.08,
        ]);
        $response->forceFill(['crop_path' => ScanFiles::cropPath($this->schoolA->id, $this->assignmentA->id, $response->id)])->save();
        Storage::disk('local')->put($response->crop_path, $this->scanFixture('crop.webp'));

        return [$submission, $scan, $response];
    }

    protected function scanFixture(string $name): string
    {
        return (string) file_get_contents(base_path('tests/fixtures/scans/'.$name));
    }

    /**
     * A valid POST /scans body for assignment A (page 1 of student A), signed
     * like a printed worksheet, so a request reaches the policy check.
     *
     * @return array<string, mixed>
     */
    protected function scanBody(): array
    {
        $qr = app(QrSigner::class)->sign($this->assignmentA->id, $this->studentA->id, 1, 1);
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => $qr,
            'scanned_at' => '2026-09-20T09:15:00+07:00',
            'blur_score' => 182.4,
            'regions' => [
                ['region_id' => 'q'.$this->shortA->id, 'question_id' => $this->shortA->id, 'file' => 'crop_short', 'ink_ratio' => 0.08, 'cnn' => ['text' => '20', 'confidence' => 0.97]],
                ['region_id' => 'q'.$this->openA->id, 'question_id' => $this->openA->id, 'file' => 'crop_open', 'ink_ratio' => 0.2],
            ],
        ];

        return [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', $this->scanFixture('page.webp')),
            'crop_short' => UploadedFile::fake()->createWithContent('crop.webp', $this->scanFixture('crop.webp')),
            'crop_open' => UploadedFile::fake()->createWithContent('crop.webp', $this->scanFixture('crop.webp')),
        ];
    }

    /**
     * A validly signed answer-sheet page of examA (POST /exam-sheets, §22.11).
     *
     * @return array<string, mixed>
     */
    protected function examSheetBody(): array
    {
        $meta = [
            'client_scan_id' => (string) Str::uuid(),
            'qr' => app(QrSigner::class)->signExamSheet($this->examA->id, $this->studentA->id, 1, 1),
            'scanned_at' => '2026-09-20T09:15:00+07:00',
            'blur_score' => 182.4,
            'rows' => ['1' => ['1' => 0.9, '2' => 0.02, '3' => 0.02, '4' => 0.02]],
        ];

        return [
            'meta' => json_encode($meta),
            'page' => UploadedFile::fake()->createWithContent('page.webp', $this->scanFixture('page.webp')),
        ];
    }
}
