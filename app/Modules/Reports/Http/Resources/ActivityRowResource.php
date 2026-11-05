<?php

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One doctor's appointments, no-show rate, and visits (US3). The resource is a row from
 * ReportService::activity.
 */
class ActivityRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'doctor' => $this->resource['doctor'],
            'appointments' => $this->resource['appointments'],
            'no_show_rate' => $this->resource['no_show_rate'],
            'visits' => $this->resource['visits'],
        ];
    }
}
