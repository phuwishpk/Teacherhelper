<?php

namespace App\Http\Resources;

use App\Models\Layout;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * {assignment_id, version, page_count, pages: [layout JSON of DESIGN §5.3], created_at}
 *
 * @mixin Layout
 */
class LayoutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'assignment_id' => $this->assignment_id,
            'version' => $this->version,
            'page_count' => $this->pageCount(),
            'pages' => $this->pages,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
