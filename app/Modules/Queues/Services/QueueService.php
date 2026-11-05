<?php

namespace App\Modules\Queues\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Exceptions\QueueConflictException;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
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
    ) {}

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
}
