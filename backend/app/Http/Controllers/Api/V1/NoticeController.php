<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Firestore\FirestoreInbox;
use App\Http\Controllers\Controller;
use App\Jobs\MarkNoticesReadJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/v1/me/notices/read {ids: [ulid, ...]} -> 202 {data: {queued}}
 * (DESIGN §38 step 2): the app sends the ids of the notices it showed; the
 * queue flips `read` on them in Firestore.
 */
class NoticeController extends Controller
{
    public const MAX_IDS = 100;

    public function read(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_IDS],
            'ids.*' => ['required', 'string', 'size:26', function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_string($value) || ! FirestoreInbox::isId($value)) {
                    $fail('รหัสแจ้งเตือนไม่ถูกต้อง');
                }
            }],
        ]);
        $ids = array_values(array_unique($data['ids']));
        MarkNoticesReadJob::dispatch($request->user()->id, $ids);

        return response()->json(['data' => ['queued' => count($ids)]], 202);
    }
}
