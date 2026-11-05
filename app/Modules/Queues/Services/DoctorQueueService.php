<?php

namespace App\Modules\Queues\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Exceptions\QueueConflictException;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A doctor's side of the queue (US3, research R5). Doctors act only on their current patient and
 * the next one, so they never send an entry id. The doctor is read before each transaction; inside
 * it, locks come first (queue day → appointment → patient, research R3).
 */
class DoctorQueueService
{
    public function __construct(
        private readonly QueueRepository $queue,
        private readonly AppointmentRepository $appointments,
        private readonly PatientRepository $patients,
        private readonly DoctorService $doctors,
        private readonly AuditTrail $audit,
    ) {}

    /**
     * The signed-in doctor's whole list for today (FR-010).
     *
     * @return Collection<int, QueueEntry>
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     */
    public function listOwn(User $user): Collection
    {
        return $this->queue->forDoctorDay($this->doctors->findOwn($user)->id, ClinicClock::today());
    }

    /**
     * Call the first waiting patient in queue order who is not with another doctor right now
     * (FR-011, approved D4).
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     * @throws ValidationException a visit is in progress, or nobody can be called
     * @throws QueueConflictException the unique index refused a patient with two doctors
     */
    public function callNext(User $user): QueueEntry
    {
        $doctorId = $this->doctors->findOwn($user)->id;
        $today = ClinicClock::today();

        try {
            $entry = DB::transaction(function () use ($doctorId, $today) {
                $this->queue->lockDay($doctorId, $today);

                if ($this->queue->withDoctor($doctorId, $today) !== null) {
                    throw $this->refusal('visit_in_progress');
                }

                foreach ($this->queue->waitingInOrder($doctorId, $today) as $entry) {
                    $this->patients->lockById($entry->patient_id);

                    if (! $this->queue->patientWithDoctor($entry->patient_id, $today)) {
                        $this->queue->update($entry, [
                            'status' => QueueStatus::WithDoctor,
                            'called_at' => now(),
                        ]);

                        $this->audit->recordChanges(AuditAction::VisitCalled, $entry, AuditSubject::Visit, patientId: $entry->patient_id);

                        return $entry;
                    }
                }

                throw $this->refusal('nobody_waiting');
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw new QueueConflictException('patient_with_another_doctor');
        }

        return $this->queue->find($entry->id);
    }

    /**
     * Finish the visit of the patient with the doctor: the entry becomes seen and its appointment,
     * if any, completed (FR-012).
     *
     * @throws DoctorNotFoundException when the doctor has no profile
     * @throws ValidationException nobody is with the doctor
     */
    public function finish(User $user): QueueEntry
    {
        $doctorId = $this->doctors->findOwn($user)->id;
        $today = ClinicClock::today();

        $entry = DB::transaction(function () use ($doctorId, $today) {
            $this->queue->lockDay($doctorId, $today);

            $entry = $this->queue->withDoctor($doctorId, $today) ?? throw $this->refusal('no_current_visit');

            if ($entry->appointment_id !== null) {
                $this->appointments->update(
                    $this->appointments->lockById($entry->appointment_id),
                    ['status' => AppointmentStatus::Completed],
                );
            }

            $this->queue->update($entry, [
                'status' => QueueStatus::Seen,
                'finished_at' => now(),
            ]);

            $this->audit->recordChanges(AuditAction::VisitFinished, $entry, AuditSubject::Visit, patientId: $entry->patient_id);

            return $entry;
        }, attempts: 3);

        return $this->queue->find($entry->id);
    }

    private function refusal(string $message): ValidationException
    {
        return ValidationException::withMessages(['queue' => __("queues::messages.{$message}")]);
    }
}
