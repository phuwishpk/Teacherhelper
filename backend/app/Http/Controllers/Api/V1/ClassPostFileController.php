<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\ClassPosts\ClassPostFileTypes;
use App\Domain\ClassPosts\ClassPosts;
use App\Domain\Classrooms\ClassroomAccess;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ClassPostFile;
use App\Models\User;
use finfo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Files of class posts (DESIGN §30.2): a teacher uploads a file, then
 * attaches it to one or several posts. The type is read from the bytes, not
 * from the name. A reader gets the bytes only through a post that attaches
 * the file: a teacher of its classroom, or a student the post reaches.
 */
class ClassPostFileController extends Controller
{
    /**
     * POST /api/v1/class-post-files multipart `file` -> 201 {data: {id,
     * name, mime_type, size_bytes, download_url}, usage: {used_bytes,
     * limit_bytes}}; 422 file_too_large, file_type_unsupported,
     * class_post_quota_exceeded.
     */
    public function store(Request $request): JsonResponse
    {
        $teacher = $request->user();
        $upload = $request->file('file');
        if (! $upload instanceof UploadedFile || ! $upload->isValid()) {
            $message = $upload instanceof UploadedFile ? 'ไฟล์ใหญ่เกินไปหรืออัปโหลดไม่สมบูรณ์' : 'เลือกไฟล์ที่จะแนบ';

            throw new ApiException($message, $upload instanceof UploadedFile ? 'file_too_large' : 'validation_failed', 422, ['file' => [$message]]);
        }
        $maxMb = (int) config('eduvision.class_posts.max_file_mb');
        $size = (int) $upload->getSize();
        if ($size <= 0 || $size > $maxMb * 1024 * 1024) {
            $message = "ไฟล์ต้องมีขนาดไม่เกิน {$maxMb} MB";

            throw new ApiException($message, 'file_too_large', 422, ['file' => [$message]]);
        }
        [$mime, $extension] = self::type($upload);

        $file = DB::transaction(function () use ($teacher, $upload, $size, $mime, $extension) {
            // One upload at a time per teacher, so two cannot both fit the last free bytes.
            User::query()->whereKey($teacher->id)->lockForUpdate()->first();
            $usage = ClassPosts::usage($teacher);
            if ($usage['limit_bytes'] < $usage['used_bytes'] + $size) {
                $message = 'พื้นที่เก็บไฟล์ของคุณเต็มแล้ว ลบโพสต์หรือไฟล์เก่าก่อน';

                throw new ApiException($message, 'class_post_quota_exceeded', 422, ['file' => [$message]]);
            }
            $path = "class-posts/{$teacher->id}/".Str::uuid().'.'.$extension;
            Storage::disk('local')->put($path, (string) file_get_contents($upload->getRealPath()));

            return ClassPostFile::create([
                'owner_id' => $teacher->id,
                'path' => $path,
                'original_name' => ClassPostFileTypes::name($upload->getClientOriginalName(), $extension),
                'mime_type' => $mime,
                'size_bytes' => $size,
                'sha256' => (string) hash_file('sha256', $upload->getRealPath()),
            ]);
        });

        return response()->json(['data' => ClassPosts::file($file), 'usage' => ClassPosts::usage($teacher)], 201);
    }

    /** GET /api/v1/class-post-files/usage -> {data: {used_bytes, limit_bytes}} */
    public function usage(Request $request): JsonResponse
    {
        return response()->json(['data' => ClassPosts::usage($request->user())]);
    }

    /** DELETE /api/v1/class-post-files/{id} -> 204; the owner, and only while no post attaches it (409 file_in_use). */
    public function destroy(Request $request, int $id): Response
    {
        $file = ClassPostFile::query()->where('owner_id', $request->user()->id)->findOrFail($id);
        if ($file->inUse()) {
            throw new ApiException('ไฟล์นี้แนบอยู่กับโพสต์หรือการบ้าน เอาออกจากที่นั่นก่อน', 'file_in_use', 409);
        }
        ClassPosts::deleteFile($file);

        return response()->noContent();
    }

    /**
     * GET /api/v1/class-post-files/{id}/file -> the bytes. The owner; a
     * teacher who sees a post attaching it; a student a published post
     * attaching it reaches. Anyone else: 404.
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        $user = $request->user();
        $file = ClassPostFile::query()->findOrFail($id);
        $posts = $user->role === User::ROLE_STUDENT ? ClassPosts::forStudent($user) : ClassPosts::forTeacher($user);
        $visible = (int) $file->owner_id === (int) $user->id
            || $posts->whereIn('class_posts.id', DB::table('class_post_attachments')->select('class_post_id')->where('class_post_file_id', $file->id))->exists()
            || self::assignmentVisible($user, $file->id);
        if (! $visible || ! Storage::disk('local')->exists($file->path)) {
            abort(404);
        }

        return Storage::disk('local')->download($file->path, $file->original_name, ['Content-Type' => $file->mime_type]);
    }

    /**
     * The file is attached to an assignment the teacher sees, or to a ready
     * homework of a classroom the student is in (DESIGN §30.12).
     */
    private static function assignmentVisible(User $user, int $fileId): bool
    {
        $ids = DB::table('assignment_attachments')->select('assignment_id')->where('class_post_file_id', $fileId);
        if ($user->role === User::ROLE_STUDENT) {
            return Assignment::query()
                ->where('status', Assignment::STATUS_READY)
                ->whereIn('classroom_id', DB::table('classroom_students')->select('classroom_id')->where('student_id', $user->id))
                ->reaching((int) $user->id)
                ->whereIn('id', $ids)
                ->exists();
        }

        return ClassroomAccess::assignments($user)->whereIn('assignments.id', $ids)->exists();
    }

    /**
     * The type read from the bytes and the extension to store it with.
     *
     * @return array{0: string, 1: string}
     */
    private static function type(UploadedFile $upload): array
    {
        // finfo on the bytes: UploadedFile::getMimeType() may fall back to the name.
        $type = ClassPostFileTypes::of(
            (string) (new finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath()),
            $upload->getClientOriginalName(),
        );
        if ($type !== null) {
            return $type;
        }
        $message = 'แนบได้เฉพาะไฟล์ PDF รูปภาพ และไฟล์ Word, Excel, PowerPoint';

        throw new ApiException($message, 'file_type_unsupported', 422, ['file' => [$message]]);
    }
}
