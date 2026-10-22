<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ActiveModelRequest;
use App\Models\ModelVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * On-device model distribution (DESIGN §9.8, §12): the active version of a
 * model and its .tflite file. The app compares version + sha256 with what it
 * has, downloads the file and verifies the hash before switching.
 */
class ModelController extends Controller
{
    public const DISK = 'local';

    /**
     * GET /api/v1/ml/models/active?name=digit_crnn -> {data: {id, name,
     * version, sha256, size_bytes, download_url, metrics, created_at}};
     * 404 model_not_found when no version of that name is active.
     */
    public function active(ActiveModelRequest $request): JsonResponse
    {
        $name = $request->name();
        $model = ModelVersion::query()->activeNamed($name)->orderByDesc('id')->first();
        if ($model === null) {
            throw new ApiException("ยังไม่มีโมเดล {$name} ที่เปิดใช้งาน", 'model_not_found', 404);
        }
        Gate::authorize('download', $model);

        return response()->json(['data' => self::describe($model)]);
    }

    /**
     * GET /api/v1/ml/models/{id}/file -> the .tflite bytes
     * (application/octet-stream, X-Checksum-Sha256). 404 model_not_found,
     * 410 model_file_missing when the file left the disk.
     */
    public function file(Request $request, int $id): StreamedResponse
    {
        $model = ModelVersion::query()->find($id);
        if ($model === null) {
            throw new ApiException('ไม่พบโมเดลนี้', 'model_not_found', 404);
        }
        Gate::authorize('download', $model);

        $disk = Storage::disk(self::DISK);
        if (! $disk->exists($model->file_path)) {
            throw new ApiException('ไฟล์โมเดลนี้ถูกลบออกจากเซิร์ฟเวอร์แล้ว', 'model_file_missing', 410);
        }

        return $disk->download($model->file_path, "{$model->name}-{$model->version}.tflite", [
            'Content-Type' => 'application/octet-stream',
            'X-Checksum-Sha256' => $model->sha256,
            'Cache-Control' => 'private, max-age=0',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function describe(ModelVersion $model): array
    {
        $disk = Storage::disk(self::DISK);

        return [
            'id' => $model->id,
            'name' => $model->name,
            'version' => $model->version,
            'sha256' => $model->sha256,
            'size_bytes' => $disk->exists($model->file_path) ? $disk->size($model->file_path) : null,
            'download_url' => route('api.ml.models.file', $model->id, false),
            'metrics' => $model->metrics,
            'is_active' => $model->is_active,
            'created_at' => $model->created_at?->toIso8601String(),
        ];
    }
}
