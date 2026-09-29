<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pages\PageFiles;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Submission;
use App\Models\SubmissionPage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of whole-page submissions (DESIGN §19.4, §19.9).
 */
class SubmissionPageController extends Controller
{
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
