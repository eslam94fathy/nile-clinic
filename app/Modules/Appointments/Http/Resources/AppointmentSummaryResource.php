<?php

namespace App\Modules\Appointments\Http\Resources;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The staff list shape (FR-009): the patient only as {id, full_name, phone}; never the note,
 * national ID, address, or emergency contact.
 *
 * @mixin Appointment
 */
class AppointmentSummaryResource extends JsonResource
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
        ];
    }
}
