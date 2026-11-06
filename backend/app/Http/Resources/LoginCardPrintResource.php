<?php

namespace App\Http\Resources;

use App\Models\LoginCardPrint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, status, classroom_id, student_id, download_url, status_url, error, created_at}
 * download_url is set only when status = ready (DESIGN §9.3 worksheet-prints shape).
 *
 * @mixin LoginCardPrint
 */
class LoginCardPrintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'classroom_id' => $this->classroom_id,
            'student_id' => $this->student_id,
            'download_url' => $this->isReady() ? route('api.login-card-prints.file', $this->id, false) : null,
            'status_url' => route('api.login-card-prints.show', $this->id, false),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
