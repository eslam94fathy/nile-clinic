<?php

namespace App\Modules\Appointments\Repositories;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Staff\Enums\Role;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
        return $this->holdingOverlapping($date, $start, $end, $exceptId)->where('doctor_id', $doctorId)->exists();
    }

    public function patientOverlaps(int $patientId, string $date, int $start, int $end, ?int $exceptId = null): bool
    {
        return $this->holdingOverlapping($date, $start, $end, $exceptId)->where('patient_id', $patientId)->exists();
    }

    public function find(int $id, CarbonImmutable $now): ?Appointment
    {
        return $this->withOutsideAvailability(Appointment::query(), $now)
            ->with(['patient', 'doctor.user', 'bookedBy', 'rescheduledBy', 'statusChangedBy'])
            ->find($id);
    }

    public function paginate(array $filters, CarbonImmutable $now, int $perPage): LengthAwarePaginator
    {
        [$expression, $bindings] = $this->outsideAvailability($now);

        return $this->withOutsideAvailability(Appointment::query(), $now)
            ->whereBetween('appointments.date', [$filters['from'], $filters['to']])
            ->when(isset($filters['doctor_id']), fn ($query) => $query->where('appointments.doctor_id', $filters['doctor_id']))
            ->when(isset($filters['patient_id']), fn ($query) => $query->where('appointments.patient_id', $filters['patient_id']))
            ->when(isset($filters['status']), fn ($query) => $query->where('appointments.status', $filters['status']))
            ->when(isset($filters['outside_availability']), fn ($query) => $query->whereRaw(
                "({$expression}) = ?",
                [...$bindings, $filters['outside_availability'] ? 1 : 0],
            ))
            ->with(['patient', 'doctor.user'])
            ->orderBy('appointments.date')
            ->orderBy('appointments.start_minute')
            ->orderBy('appointments.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginateForDoctor(int $doctorId, array $filters, CarbonImmutable $now, int $perPage): LengthAwarePaginator
    {
        return $this->withOutsideAvailability(Appointment::query(), $now)
            ->where('appointments.doctor_id', $doctorId)
            ->whereBetween('appointments.date', [$filters['from'], $filters['to']])
            ->when(isset($filters['status']), fn ($query) => $query->where('appointments.status', $filters['status']))
            ->with('patient')
            ->orderBy('appointments.date')
            ->orderBy('appointments.start_minute')
            ->orderBy('appointments.id')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function findForDoctor(int $doctorId, int $id, CarbonImmutable $now): ?Appointment
    {
        return $this->withOutsideAvailability(Appointment::query(), $now)
            ->where('appointments.doctor_id', $doctorId)
            ->with('patient')
            ->find($id);
    }

    /**
     * @param  Builder<Appointment>  $query
     * @return Builder<Appointment>
     */
    private function withOutsideAvailability(Builder $query, CarbonImmutable $now): Builder
    {
        [$expression, $bindings] = $this->outsideAvailability($now);

        return $query->selectRaw("appointments.*, ({$expression}) AS outside_availability", $bindings);
    }

    /**
     * Whether a scheduled appointment that has not started (as of the clinic's $now) is outside
     * the doctor's current availability (research R7, FR-018): no working period of its weekday
     * covers it, time off overlaps it, or the doctor is not active. Only coverage by a period
     * counts, not the slot grid, so an appointment length change alone never sets it.
     * MySQL DAYOFWEEK() is 1 for Sunday; Scheduling's weekday is 0 for Sunday.
     *
     * Positional bindings, in this order: scheduled status, today, today, minute of the day, the
     * doctor role (MySQL PDO does not repeat named bindings).
     *
     * @return array{0: string, 1: list<int|string>}
     */
    private function outsideAvailability(CarbonImmutable $now): array
    {
        $expression = <<<'SQL'
            appointments.status = ?
            AND (appointments.date > ? OR (appointments.date = ? AND appointments.start_minute > ?))
            AND (
                NOT EXISTS (
                    SELECT 1 FROM doctor_schedules s
                    JOIN schedule_periods p ON p.doctor_schedule_id = s.id
                    WHERE s.doctor_id = appointments.doctor_id
                        AND p.weekday = DAYOFWEEK(appointments.date) - 1
                        AND p.start_minute <= appointments.start_minute
                        AND p.end_minute >= appointments.end_minute
                )
                OR EXISTS (
                    SELECT 1 FROM doctor_time_off t
                    WHERE t.doctor_id = appointments.doctor_id
                        AND t.start_date <= appointments.date
                        AND t.end_date >= appointments.date
                        AND (t.start_minute IS NULL OR (t.start_minute < appointments.end_minute AND t.end_minute > appointments.start_minute))
                )
                OR NOT EXISTS (
                    SELECT 1 FROM doctors d
                    JOIN users u ON u.id = d.user_id
                    WHERE d.id = appointments.doctor_id AND u.is_active = 1 AND u.role = ?
                )
            )
            SQL;

        $today = $now->toDateString();

        return [$expression, [
            AppointmentStatus::Scheduled->value,
            $today,
            $today,
            $now->hour * 60 + $now->minute,
            Role::Doctor->value,
        ]];
    }

    /**
     * Appointments holding their slot (scheduled, checked in, or completed) that overlap the time.
     *
     * @return Builder<Appointment>
     */
    private function holdingOverlapping(string $date, int $start, int $end, ?int $exceptId): Builder
    {
        return Appointment::query()
            ->whereIn('status', AppointmentStatus::holdingSlot())
            ->where('date', $date)
            ->where('start_minute', '<', $end)
            ->where('end_minute', '>', $start)
            ->when($exceptId !== null, fn ($query) => $query->whereKeyNot($exceptId));
    }
}
