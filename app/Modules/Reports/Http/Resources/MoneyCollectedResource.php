<?php

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The money collected report (US1). The resource is the array from ReportService::moneyCollected, whose
 * lists are already shaped and whose money is already strings.
 */
class MoneyCollectedResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'from' => $this->resource['from'],
            'to' => $this->resource['to'],
            'totals' => $this->resource['totals'],
            'by_method' => $this->resource['by_method'],
            'by_day' => $this->resource['by_day'],
        ];
    }
}
