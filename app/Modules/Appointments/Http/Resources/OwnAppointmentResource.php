<?php

namespace App\Modules\Appointments\Http\Resources;

use App\Modules\Appointments\Models\Appointment;
use Illuminate\Http\Request;

/**
 * One of a doctor's own appointments (FR-015): the own summary plus the booking note and when it
 * was booked.
 *
 * @mixin Appointment
 */
class OwnAppointmentResource extends OwnAppointmentSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'note' => $this->note,
            'booked_at' => $this->created_at?->toISOString(),
        ];
    }
}
