<?php

namespace App\Modules\Queues\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Exceptions\QueueConflictException;
use App\Modules\Queues\Exceptions\QueueEntryNotFoundException;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Queues\Repositories\Contracts\VisitChargeRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reception's side of the queue (research R3, R6).
 *
 * Locks first (research R3): InnoDB takes the transaction's read view at the first plain read, so
 * inside a transaction nothing is read plainly before the locks are held.
 */
class QueueService
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly AppointmentRepository $appointments,
        private readonly DoctorService $doctors,
        private readonly DoctorRepository $doctorRecords,
        private readonly PatientRepository $patients,
        private readonly VisitChargeRepository $visitCharges,
    ) {}

    /**
     * Today's entries of every doctor (FR-007).
     *
     * @return LengthAwarePaginator<int, QueueEntry>
     */
    public function list(?string $status, int $perPage): LengthAwarePaginator
    {
        return $this->queue->paginateDay(ClinicClock::today(), $status, $perPage);
    }

    /**
     * One doctor's whole list for today (FR-007).
     *
     * @return Collection<int, QueueEntry>
     *
     * @throws DoctorNotFoundException
     */
    public function listForDoctor(int $doctorId): Collection
    {
        $this->doctors->find($doctorId);

        return $this->queue->forDoctorDay($doctorId, ClinicClock::today());
    }

    /**
     * Check in the patient of a scheduled appointment dated the clinic's today, at any time that
     * day (FR-001–FR-004). The appointment's doctor never changes, so it is read before the
     * transaction. Lock order queue day → appointment → patient, research R3.
     *
     * @throws AppointmentNotFoundException
     * @throws ValidationException when not scheduled or not dated today
     * @throws QueueConflictException the unique index on the appointment refused a second entry
     */
    public function checkIn(User $actor, int $appointmentId): QueueEntry
    {
        $appointment = $this->appointments->find($appointmentId, ClinicClock::now()) ?? throw new AppointmentNotFoundException;
        $today = ClinicClock::today();

        try {
            $entry = DB::transaction(function () use ($actor, $appointmentId, $appointment, $today) {
                $day = $this->queue->lockDay($appointment->doctor_id, $today);
                $locked = $this->appointments->lockById($appointmentId) ?? throw new AppointmentNotFoundException;

                if ($locked->status !== AppointmentStatus::Scheduled || $locked->date->toDateString() !== $today) {
                    throw ValidationException::withMessages(['appointment' => __('queues::messages.not_checkable')]);
                }

                $this->appointments->update($locked, ['status' => AppointmentStatus::CheckedIn]);

                return $this->queue->create([
                    'doctor_id' => $locked->doctor_id,
                    'patient_id' => $locked->patient_id,
                    'appointment_id' => $locked->id,
                    'date' => $today,
                    'queue_number' => $this->queue->takeNumber($day),
                    'order_minute' => $locked->start_minute,
                    'status' => QueueStatus::Waiting,
                    'arrived_at' => now(),
                    'checked_in_by' => $actor->id,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new QueueConflictException('already_checked_in');
        }

        return $this->queue->find($entry->id);
    }

    /**
     * Add a registered patient without an appointment to an active doctor's list today; no slot is
     * taken (FR-006, research R6). The doctor is read before the transaction: a plain read inside
     * it would fix the read view before the locks, and patientActive would miss an entry committed
     * by the previous lock holder (research R3).
     *
     * @throws ValidationException unknown patient, unknown or inactive doctor, or already in the list
     */
    public function addWalkIn(User $actor, int $patientId, int $doctorId): QueueEntry
    {
        $doctor = $this->doctorRecords->findById($doctorId) ?? throw ValidationException::withMessages([
            'doctor_id' => __('queues::messages.doctor_not_found'),
        ]);

        if (! $doctor->isActive()) {
            throw ValidationException::withMessages(['doctor_id' => __('queues::messages.doctor_inactive')]);
        }

        $today = ClinicClock::today();

        $entry = DB::transaction(function () use ($actor, $patientId, $doctorId, $today) {
            $day = $this->queue->lockDay($doctorId, $today);

            $this->patients->lockById($patientId) ?? throw ValidationException::withMessages([
                'patient_id' => __('queues::messages.patient_not_found'),
            ]);

            if ($this->queue->patientActive($doctorId, $patientId, $today)) {
                throw ValidationException::withMessages(['patient_id' => __('queues::messages.already_in_queue')]);
            }

            $now = ClinicClock::now();

            return $this->queue->create([
                'doctor_id' => $doctorId,
                'patient_id' => $patientId,
                'appointment_id' => null,
                'date' => $today,
                'queue_number' => $this->queue->takeNumber($day),
                'order_minute' => $now->hour * 60 + $now->minute,
                'status' => QueueStatus::Waiting,
                'arrived_at' => now(),
                'checked_in_by' => $actor->id,
            ]);
        }, attempts: 3);

        return $this->queue->find($entry->id);
    }

    /**
     * Mark a waiting patient as left; the entry stays in the list for the record (FR-014).
     *
     * @throws QueueEntryNotFoundException
     * @throws ValidationException when not waiting
     */
    public function leave(User $actor, int $id): QueueEntry
    {
        $current = $this->queue->find($id) ?? throw new QueueEntryNotFoundException;

        DB::transaction(function () use ($actor, $id, $current) {
            $entry = $this->lockWaiting($id, $current);

            $this->queue->update($entry, [
                'status' => QueueStatus::Left,
                'left_at' => now(),
                'left_by' => $actor->id,
            ]);
        }, attempts: 3);

        return $this->queue->find($id);
    }

    /**
     * Undo the check-in of a waiting entry: the entry is removed and the appointment is scheduled
     * again. The queue day's last number is unchanged, so the number is not reused (FR-003, FR-014).
     *
     * @throws QueueEntryNotFoundException
     * @throws ValidationException when not waiting, a walk-in, or it has an active charge (feature 009)
     */
    public function undo(int $id): void
    {
        $current = $this->queue->find($id) ?? throw new QueueEntryNotFoundException;

        DB::transaction(function () use ($id, $current) {
            $entry = $this->lockWaiting($id, $current);

            if ($this->visitCharges->hasActiveCharge($entry->id)) {
                throw ValidationException::withMessages(['entry' => __('queues::messages.has_active_charge')]);
            }

            if ($entry->appointment_id === null) {
                throw $this->notChangeable();
            }

            $this->appointments->update(
                $this->appointments->lockById($entry->appointment_id),
                ['status' => AppointmentStatus::Scheduled],
            );

            $this->queue->delete($entry);
        }, attempts: 3);
    }

    /**
     * Locks the entry's queue day, then the entry (research R3). The doctor and date of an entry
     * never change, so they come from the plain read before the transaction.
     *
     * @throws QueueEntryNotFoundException
     * @throws ValidationException when not waiting
     */
    private function lockWaiting(int $id, QueueEntry $current): QueueEntry
    {
        $this->queue->lockDay($current->doctor_id, $current->date->toDateString());

        $entry = $this->queue->lockById($id) ?? throw new QueueEntryNotFoundException;

        if ($entry->status !== QueueStatus::Waiting) {
            throw $this->notChangeable();
        }

        return $entry;
    }

    private function notChangeable(): ValidationException
    {
        return ValidationException::withMessages(['entry' => __('queues::messages.not_changeable')]);
    }
}
