<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finding appointments (US2) and changing their status (US3, US4).
 */
class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
    ) {}

    /**
     * @param  array{from: string, to: string, doctor_id?: int, patient_id?: int, status?: string, outside_availability?: bool}  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->appointments->paginate($filters, ClinicClock::now(), $perPage);
    }

    /**
     * @throws AppointmentNotFoundException
     */
    public function find(int $id): Appointment
    {
        return $this->appointments->find($id, ClinicClock::now()) ?? throw new AppointmentNotFoundException;
    }

    /**
     * Cancel a scheduled appointment that has not started; its slot is freed (FR-012, FR-014).
     * The row is locked, so a cancel and a no-show at the same moment cannot both apply (R5).
     *
     * @throws AppointmentNotFoundException
     * @throws ValidationException when cancelled, no-show, or started
     */
    public function cancel(User $actor, int $id, ?string $reason): Appointment
    {
        DB::transaction(function () use ($actor, $id, $reason) {
            $appointment = $this->lockChangeable($id);

            if ($appointment->hasStarted(ClinicClock::now())) {
                throw $this->refusal('not_changeable');
            }

            $this->appointments->update($appointment, [
                'status' => AppointmentStatus::Cancelled,
                'status_changed_by' => $actor->id,
                'status_changed_at' => now(),
                'cancellation_reason' => $reason,
            ]);
        });

        return $this->find($id);
    }

    /**
     * The appointment, locked until the transaction ends, if it is still scheduled.
     *
     * @throws AppointmentNotFoundException
     * @throws ValidationException
     */
    private function lockChangeable(int $id): Appointment
    {
        $appointment = $this->appointments->lockById($id) ?? throw new AppointmentNotFoundException;

        if ($appointment->status !== AppointmentStatus::Scheduled) {
            throw $this->refusal('not_changeable');
        }

        return $appointment;
    }

    private function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['appointment' => __("appointments::messages.{$message}")]);
    }
}
