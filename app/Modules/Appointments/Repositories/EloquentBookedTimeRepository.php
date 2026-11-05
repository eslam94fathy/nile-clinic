<?php

namespace App\Modules\Appointments\Repositories;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Scheduling\Repositories\Contracts\BookedTimeRepository;

/**
 * The Appointments side of approved decision D1 (research R2): Scheduling asks which times are
 * taken; scheduled, checked-in, and completed appointments take a time (feature 007, research R2).
 */
class EloquentBookedTimeRepository implements BookedTimeRepository
{
    public function forDoctor(int $doctorId, string $from, string $to): array
    {
        return Appointment::query()
            ->where('doctor_id', $doctorId)
            ->whereIn('status', AppointmentStatus::holdingSlot())
            ->whereBetween('date', [$from, $to])
            ->orderBy('date')
            ->orderBy('start_minute')
            ->get(['date', 'start_minute', 'end_minute'])
            ->map(fn (Appointment $appointment) => [
                'date' => $appointment->date->toDateString(),
                'start_minute' => $appointment->start_minute,
                'end_minute' => $appointment->end_minute,
            ])
            ->all();
    }
}
