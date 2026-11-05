<?php

namespace App\Modules\Reports\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One doctor's billing figures (US2). The resource is a row from ReportService::billing, whose money is
 * already strings.
 */
class BillingRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'doctor' => $this->resource['doctor'],
            'active_charges' => $this->resource['active_charges'],
            'fees' => $this->resource['fees'],
            'discounts' => $this->resource['discounts'],
            'amount_due' => $this->resource['amount_due'],
            'amount_paid' => $this->resource['amount_paid'],
            'balance' => $this->resource['balance'],
            'cancelled_charges' => $this->resource['cancelled_charges'],
            'uncharged_seen_visits' => $this->resource['uncharged_seen_visits'],
        ];
    }
}
