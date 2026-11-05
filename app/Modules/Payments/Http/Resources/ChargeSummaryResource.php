<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A charge in a list: its figures, with no reasons, payments, or refunds. Only the patient summary is
 * shown (FR-019).
 *
 * @mixin Charge
 */
class ChargeSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visit_id' => $this->queue_entry_id,
            'status' => $this->status->value,
            'date' => $this->date->toDateString(),
            'doctor' => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->user->name,
                'title' => $this->doctor->title,
            ],
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'phone' => $this->patient->phone,
            ],
            'fee' => $this->fee,
            'discount' => $this->discount,
            'amount_due' => Money::due($this->fee, $this->discount),
            'amount_paid' => $this->amount_paid,
            'balance' => Money::balance($this->fee, $this->discount, $this->amount_paid),
            'charged_at' => $this->created_at->toISOString(),
        ];
    }
}
