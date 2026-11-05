<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Finding appointments (US2, US5) and changing their status (US3, US4).
 */
class AppointmentService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly DoctorService $doctors,
        private readonly AuditTrail $audit,
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
     * The signed-in doctor's own appointments (FR-015).
     *
     * @param  array{from: string, to: string, status?: string}  $filters
     * @return LengthAwarePaginator<int, Appointment>
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     */
    public function listOwn(User $user, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->appointments->paginateForDoctor($this->doctors->findOwn($user)->id, $filters, ClinicClock::now(), $perPage);
    }

    /**
     * Another doctor's appointment is reported as not found (research R8).
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     * @throws AppointmentNotFoundException
     */
    public function findOwn(User $user, int $id): Appointment
    {
        return $this->appointments->findForDoctor($this->doctors->findOwn($user)->id, $id, ClinicClock::now())
            ?? throw new AppointmentNotFoundException;
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

            $this->audit->recordChanges(AuditAction::AppointmentCancelled, $appointment, AuditSubject::Appointment, patientId: $appointment->patient_id);
        });

        return $this->find($id);
    }

    /**
     * Record that the patient did not come: only a scheduled appointment that has started
     * (FR-013, FR-014, research R5).
     *
     * @throws AppointmentNotFoundException
     * @throws ValidationException when cancelled or already no-show, or not started yet
     */
    public function markNoShow(User $actor, int $id): Appointment
    {
        DB::transaction(function () use ($actor, $id) {
            $appointment = $this->lockChangeable($id);

            if (! $appointment->hasStarted(ClinicClock::now())) {
                throw $this->refusal('not_started');
            }

            $this->appointments->update($appointment, [
                'status' => AppointmentStatus::NoShow,
                'status_changed_by' => $actor->id,
                'status_changed_at' => now(),
            ]);

            $this->audit->recordChanges(AuditAction::AppointmentNoShow, $appointment, AuditSubject::Appointment, patientId: $appointment->patient_id);
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
