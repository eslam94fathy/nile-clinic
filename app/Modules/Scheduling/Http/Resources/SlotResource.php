<?php

namespace App\Modules\Scheduling\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One bookable slot, in clinic wall-clock time (FR-011, FR-020).
 *
 * @property array{date: string, start: string, end: string} $resource
 */
class SlotResource extends JsonResource
{
    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return [
            'date' => $this->resource['date'],
            'start' => $this->resource['start'],
            'end' => $this->resource['end'],
        ];
    }
}
