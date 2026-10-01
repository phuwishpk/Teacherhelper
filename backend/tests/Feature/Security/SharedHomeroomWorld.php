<?php

namespace Tests\Feature\Security;

use App\Domain\Scans\ScanFiles;
use App\Models\Assignment;
use App\Models\Classroom;
use App\Models\ClassroomCourseRequest;
use App\Models\Course;
use App\Models\ExamSection;
use App\Models\Question;
use App\Models\QuestionOption;
use App\Models\Response;
use App\Models\Scan;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Shared homerooms (DESIGN §24.7, §24.8) on top of SecurityWorld: teacher S
 * teaches course S in teacher A's classroom A (an approved binding) with one
 * graded homework, teacher P only has a pending request for classroom A, and
 * teacher A2 is the homeroom teacher of classroom A2 (another class of the
 * school, with its own student) to which teacher A asked to bind course A.
 */
trait SharedHomeroomWorld
{
    /** Subject teacher of classroom A (course S approved). */
    protected User $teacherS;

    /** Same school, a request for classroom A still pending. */
    protected User $teacherP;

    protected Course $courseS;

    protected Course $courseP;

    /** Homework of course S in classroom A, graded for student A (not published). */
    protected Assignment $assignmentS;

    protected Question $questionS;

    protected Submission $submissionS;

    protected Scan $scanS;

    protected Response $responseS;

    /** An exam of course S in classroom A with one mcq section and question. */
    protected Assignment $examS;

    /** Teacher A2's classroom, with student "other". */
    protected Classroom $classroomA2;

    /** A student of school A in another classroom (A2). */
    protected User $studentOther;

    /** Teacher P -> classroom A, pending (teacher A's incoming box). */
    protected ClassroomCourseRequest $incomingRequest;

    /** Teacher A -> classroom A2 with course A, pending (teacher A's outgoing box). */
    protected ClassroomCourseRequest $outgoingRequest;

    protected function makeSharedHomeroomWorld(): void
    {
        $this->teacherS = $this->makeTeacher($this->schoolA, ['name' => 'ครูประจำวิชา']);
        $this->teacherP = $this->makeTeacher($this->schoolA, ['name' => 'ครูรออนุมัติ']);
        $this->courseS = $this->makeCourse($this->teacherS, [$this->classroomA], ['code' => 'ว14101', 'name' => 'วิทยาศาสตร์ 4', 'grade_level' => 4, 'subject_id' => $this->subject->id]);
        $this->courseP = $this->makeCourse($this->teacherP, [], ['code' => 'ส14101', 'name' => 'สังคมศึกษา 4', 'grade_level' => 4, 'subject_id' => $this->subject->id]);
        $this->courseS->indicators()->attach($this->curriculumSkill->id);

        $this->classroomA2 = $this->makeClassroom($this->teacherA2, ['name' => 'ป.4/2']);
        $this->studentOther = $this->enrollStudent($this->classroomA2, 1, 'นักเรียนห้องอื่น')['student'];

        $this->incomingRequest = ClassroomCourseRequest::create([
            'classroom_id' => $this->classroomA->id, 'course_id' => $this->courseP->id, 'requested_by' => $this->teacherP->id, 'message' => 'ขอสอนสังคม',
        ]);
        $this->outgoingRequest = ClassroomCourseRequest::create([
            'classroom_id' => $this->classroomA2->id, 'course_id' => $this->courseA->id, 'requested_by' => $this->teacherA->id,
        ]);

        $this->assignmentS = Assignment::factory()->for_classroom($this->classroomA)->create([
            'subject_id' => $this->subject->id, 'course_id' => $this->courseS->id, 'created_by' => $this->teacherS->id,
            'title' => 'งานวิทย์', 'status' => Assignment::STATUS_READY, 'mode' => Assignment::MODE_FREEFORM,
        ]);
        $this->questionS = Question::factory()->short()->create(['assignment_id' => $this->assignmentS->id, 'position' => 1]);
        $this->questionS->skills()->attach($this->skillA->id);
        $this->submissionS = Submission::create(['assignment_id' => $this->assignmentS->id, 'student_id' => $this->studentA->id, 'status' => Submission::STATUS_NEEDS_REVIEW]);
        $this->scanS = Scan::create([
            'client_scan_id' => (string) Str::uuid(), 'submission_id' => $this->submissionS->id, 'page_no' => 1, 'layout_version' => 1,
            'uploaded_by' => $this->teacherS->id, 'scanned_at' => now(), 'blur_score' => 150.0, 'state' => Scan::STATE_ACTIVE,
            'page_image_path' => ScanFiles::pagePath($this->schoolA->id, $this->assignmentS->id, 1),
        ]);
        Storage::disk('local')->put($this->scanS->page_image_path, $this->scanFixture('page.webp'));
        $this->responseS = Response::create([
            'submission_id' => $this->submissionS->id, 'question_id' => $this->questionS->id, 'scan_id' => $this->scanS->id,
            'crop_path' => ScanFiles::cropPath($this->schoolA->id, $this->assignmentS->id, 1),
            'grading_state' => Response::STATE_SCORED, 'ai_score' => 1.0, 'ai_understanding' => 'partial',
            'review_priority' => 0.3, 'priority_band' => 'look', 'explanation' => 'ลองอีกครั้ง',
        ]);
        Storage::disk('local')->put($this->responseS->crop_path, $this->scanFixture('crop.webp'));

        $this->examS = Assignment::factory()->for_classroom($this->classroomA)->create([
            'subject_id' => $this->subject->id, 'course_id' => $this->courseS->id, 'created_by' => $this->teacherS->id,
            'kind' => Assignment::KIND_EXAM, 'grading_method' => Assignment::GRADING_APP, 'due_at' => now()->addWeek(), 'title' => 'สอบวิทย์',
        ]);
        $section = ExamSection::create(['assignment_id' => $this->examS->id, 'position' => 1, 'type' => ExamSection::TYPE_MCQ, 'option_count' => 4]);
        $question = Question::create([
            'assignment_id' => $this->examS->id, 'section_id' => $section->id, 'position' => 1, 'type' => Question::TYPE_MCQ,
            'prompt_text' => '1 + 1 = ?', 'max_points' => 1, 'answer_key' => ['accepted_options' => [2]],
            'origin' => Question::ORIGIN_TEACHER, 'approved_at' => now(),
        ]);
        foreach (['1', '2', '3', '4'] as $i => $text) {
            QuestionOption::create(['question_id' => $question->id, 'position' => $i + 1, 'text' => $text]);
        }
    }
}
