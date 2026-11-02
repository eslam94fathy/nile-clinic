<?php

namespace App\Modules\Appointments\Repositories;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

class EloquentAppointmentRepository implements AppointmentRepository
{
    public function create(array $attributes): Appointment
    {
        return Appointment::query()->create($attributes);
    }

    public function update(Appointment $appointment, array $attributes): Appointment
    {
        $appointment->fill($attributes)->save();

        return $appointment;
    }

    public function lockById(int $id): ?Appointment
    {
        return Appointment::query()->lockForUpdate()->find($id);
    }

    public function doctorOverlaps(int $doctorId, string $date, int $start, int $end, ?int $exceptId = null): bool
    {
        return $this->scheduledOverlapping($date, $start, $end, $exceptId)->where('doctor_id', $doctorId)->exists();
    }

    public function patientOverlaps(int $patientId, string $date, int $start, int $end, ?int $exceptId = null): bool
    {
        return $this->scheduledOverlapping($date, $start, $end, $exceptId)->where('patient_id', $patientId)->exists();
    }

    public function find(int $id, CarbonImmutable $now): ?Appointment
    {
        return Appointment::query()
            ->selectRaw('appointments.*, false AS outside_availability')
            ->with(['patient', 'doctor.user', 'bookedBy', 'rescheduledBy', 'statusChangedBy'])
            ->find($id);
    }

    /**
     * @return Builder<Appointment>
     */
    private function scheduledOverlapping(string $date, int $start, int $end, ?int $exceptId): Builder
    {
        return Appointment::query()
            ->where('status', AppointmentStatus::Scheduled->value)
            ->where('date', $date)
            ->where('start_minute', '<', $end)
            ->where('end_minute', '>', $start)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId));
    }
}
