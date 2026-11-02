<?php

namespace App\Modules\Appointments\Http\Resources;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A doctor's own list shape (FR-016): the patient only as {id, full_name, sex, date_of_birth};
 * no phone, note, national ID, address, or emergency contact, and no doctor (it is the reader).
 *
 * @mixin Appointment
 */
class OwnAppointmentSummaryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'start' => ClockTime::fromMinutes($this->start_minute),
            'end' => ClockTime::fromMinutes($this->end_minute),
            'status' => $this->status->value,
            'outside_availability' => (bool) $this->outside_availability,
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'sex' => $this->patient->sex->value,
                'date_of_birth' => $this->patient->date_of_birth->toDateString(),
            ],
        ];
    }
}
