<?php

namespace App\Http\Resources;

use App\Models\WorksheetPrint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {id, status, assignment_id, layout_version, download_url, status_url, error, created_at}
 * download_url is set only when status = ready (same shape as login-card prints).
 *
 * @mixin WorksheetPrint
 */
class WorksheetPrintResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'assignment_id' => $this->assignment_id,
            'layout_version' => $this->layout_version,
            'download_url' => $this->isReady() ? route('api.worksheet-prints.file', $this->id, false) : null,
            'status_url' => route('api.worksheet-prints.show', $this->id, false),
            'error' => $this->error,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
