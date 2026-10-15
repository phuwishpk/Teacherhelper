<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Exams\ExamGuard;
use App\Domain\Pages\PageFiles;
use App\Domain\Pages\PageUploads;
use App\Domain\Pages\WholePageSubmissions;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ClassroomStudent;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of whole-page submissions (DESIGN §19.4, §19.9).
 */
class SubmissionPageController extends Controller
{
    public function __construct(private readonly WholePageSubmissions $submissions) {}

    /**
     * POST /api/v1/assignments/{id}/students/{student_id}/pages multipart
     * files[] -> 201 {data: {submission_id, student_id, pages: [{id,
     * position, mime_type, page_count, size_bytes, state}], grading,
     * waiting_key, regrade_pending}}: the teacher hands in a student's work
     * from files (photos or PDF, DESIGN §19.6) into the whole-page path
     * (§19.4). The classroom's teacher only (404 otherwise); the student
     * must be enrolled in the assignment's classroom (404). File rules:
     * PageUploads (422 too_many_pages, file_too_large, unsupported_file_type,
     * pdf_unreadable). The late policy does not apply (the teacher decides);
     * a late mark from the student's own hand-in is kept.
     * Graded at once when the submission has nothing graded yet; otherwise
     * regrade_pending until POST /submissions/{id}/grade, and nothing is
     * graded before the answer key is approved (waiting_key, §19.5).
     */
    public function store(Request $request, int $id, int $studentId): JsonResponse
    {
        $assignment = AssignmentController::ownQuery($request)->with('classroom')->findOrFail($id);
        Gate::authorize('scan', $assignment);
        ExamGuard::homeworkOnly($assignment);
        ClassroomStudent::query()
            ->where('classroom_id', $assignment->classroom_id)
            ->where('student_id', $studentId)
            ->firstOrFail();

        $files = PageUploads::check($request->file('files'));
        // A late mark the student's own hand-in got stays; the teacher's upload adds none.
        $late = (bool) Submission::query()->where('assignment_id', $assignment->id)->where('student_id', $studentId)->value('late');
        $received = $this->submissions->receive($assignment, $studentId, $files, SubmissionPage::SOURCE_TEACHER_UPLOAD, [
            'uploaded_by' => $request->user()->id,
            'submitted_at' => now(),
            'late' => $late,
        ], false);
        $submission = $received['submission'];
        $pages = SubmissionPage::query()
            ->whereKey(array_map(fn (SubmissionPage $p) => $p->id, $received['pages']))
            ->orderBy('position')
            ->get();

        return response()->json(['data' => [
            'submission_id' => $submission->id,
            'student_id' => $studentId,
            'pages' => $pages->map(fn (SubmissionPage $p) => PageUploads::toApi($p))->values(),
            'grading' => $received['grading'],
            'waiting_key' => $received['waiting_key'],
            'regrade_pending' => (bool) $submission->regrade_pending,
        ]], 201);
    }

    /**
     * GET /api/v1/submission-pages/{id}/image: the file as it was handed in
     * (JPEG, PNG, WebP, HEIC/HEIF or PDF; the server never converts it, so
     * the app shows "แสดงภาพนี้บนเครื่องนี้ไม่ได้" with a download button for
     * what it cannot draw). The classroom's teacher, or the student it
     * belongs to once published; anything else 404. 410 image_purged after
     * the retention period.
     */
    public function image(Request $request, int $id): StreamedResponse
    {
        $user = $request->user();
        $page = SubmissionPage::query()
            ->with('submission.assignment.classroom')
            ->whereIn('submission_id', Submission::query()->select('id')
                ->whereIn('assignment_id', Assignment::query()->select('id')->where('school_id', $user->school_id))
                ->when($user->isStudent(), fn ($q) => $q
                    ->where('student_id', $user->id)
                    ->where('status', Submission::STATUS_PUBLISHED)))
            ->findOrFail($id);
        Gate::authorize('view', $page->submission);

        $disk = PageFiles::disk();
        if ($page->file_path === null || ! $disk->exists($page->file_path)) {
            throw new ApiException('ไฟล์งานนี้ถูกลบตามนโยบายการเก็บข้อมูลแล้ว', 'image_purged', 410);
        }

        return $disk->response($page->file_path, "page-{$page->id}.".(PageFiles::TYPES[$page->mime_type] ?? 'bin'), [
            'Content-Type' => $page->mime_type,
            'Cache-Control' => 'private, no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
