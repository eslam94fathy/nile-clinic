<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Support\Money;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a date's visits with its active charge, if any. Only the patient summary is shown (FR-019).
 * The `charge_*` attributes come from ChargeRepository::paginateVisits.
 *
 * @mixin QueueEntry
 */
class VisitChargeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'status' => $this->status->value,
            'walk_in' => $this->appointment_id === null,
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
            'charge_status' => $this->charge_status ?? 'none',
            'charge_id' => $this->charge_id,
            'balance' => $this->charge_id === null
                ? null
                : Money::balance($this->charge_fee, $this->charge_discount, $this->charge_amount_paid),
        ];
    }
}
