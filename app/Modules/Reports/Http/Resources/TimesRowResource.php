<?php

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One doctor's average wait and consultation, and counts of medical records (US4). The resource is a row
 * from ReportService::times; it holds no clinical content.
 */
class TimesRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'doctor' => $this->resource['doctor'],
            'wait' => $this->resource['wait'],
            'consultation' => $this->resource['consultation'],
            'records' => $this->resource['records'],
        ];
    }
}
