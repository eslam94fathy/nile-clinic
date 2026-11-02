<?php

namespace App\Modules\Appointments\Services;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Exceptions\AppointmentConflictException;
use App\Modules\Appointments\Exceptions\AppointmentNotFoundException;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Repositories\Contracts\AppointmentRepository;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Patients\Repositories\Contracts\PatientRepository;
use App\Modules\Scheduling\Repositories\Contracts\DoctorScheduleRepository;
use App\Modules\Scheduling\Services\SlotService;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Staff\Models\User;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Booking a patient into a slot (US1, FR-001–FR-007) and moving a booking (US3, FR-011),
 * research R3, R4.
 *
 * Locks first — no plain read before them (research R3): InnoDB takes the transaction's read view
 * at the first plain read, so taking the doctor's schedule row and the patient row FOR UPDATE
 * before anything else makes the checks see every booking committed by the previous lock holder.
 */
class BookingService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly DoctorScheduleRepository $schedules,
        private readonly PatientRepository $patients,
        private readonly DoctorRepository $doctors,
        private readonly SlotService $slots,
    ) {}

    /**
     * @param  array{patient_id: int, doctor_id: int, date: string, start: string, note?: ?string}  $input
     *
     * @throws ValidationException unknown patient or doctor, or not an offered slot
     * @throws AppointmentConflictException the doctor or the patient is already booked then
     */
    public function book(User $actor, array $input): Appointment
    {
        $doctorId = (int) $input['doctor_id'];
        $patientId = (int) $input['patient_id'];

        $appointment = $this->guardSlotUnique(fn () => DB::transaction(function () use ($actor, $input, $doctorId, $patientId) {
            $schedule = $this->schedules->lockForDoctor($doctorId);

            $this->patients->lockById($patientId) ?? throw ValidationException::withMessages([
                'patient_id' => __('appointments::messages.patient_not_found'),
            ]);

            $this->doctors->findById($doctorId) ?? throw ValidationException::withMessages([
                'doctor_id' => __('appointments::messages.doctor_not_found'),
            ]);

            [$start, $end] = $this->takeSlot($schedule !== null, $doctorId, $patientId, $input['date'], $input['start']);

            return $this->appointments->create([
                'patient_id' => $patientId,
                'doctor_id' => $doctorId,
                'date' => $input['date'],
                'start_minute' => $start,
                'end_minute' => $end,
                'status' => AppointmentStatus::Scheduled,
                'note' => $input['note'] ?? null,
                'booked_by' => $actor->id,
            ]);
        }, attempts: 3));

        return $this->appointments->find($appointment->id, ClinicClock::now());
    }

    /**
     * Move a scheduled appointment that has not started to another slot of the same doctor
     * (FR-011). Lock order: appointment, then schedule, then patient (research R3). The
     * appointment itself is excluded from the overlap checks, so it may move into a slot that
     * overlaps its own current time (research R4).
     *
     * @throws AppointmentNotFoundException
     * @throws ValidationException not changeable, or not an offered slot
     * @throws AppointmentConflictException
     */
    public function reschedule(User $actor, int $appointmentId, string $date, string $start): Appointment
    {
        $this->guardSlotUnique(fn () => DB::transaction(function () use ($actor, $appointmentId, $date, $start) {
            $appointment = $this->appointments->lockById($appointmentId) ?? throw new AppointmentNotFoundException;

            if ($appointment->status !== AppointmentStatus::Scheduled || $appointment->hasStarted(ClinicClock::now())) {
                throw ValidationException::withMessages(['appointment' => __('appointments::messages.not_changeable')]);
            }

            $schedule = $this->schedules->lockForDoctor($appointment->doctor_id);
            $this->patients->lockById($appointment->patient_id);

            [$startMinute, $endMinute] = $this->takeSlot($schedule !== null, $appointment->doctor_id, $appointment->patient_id, $date, $start, $appointment->id);

            $this->appointments->update($appointment, [
                'date' => $date,
                'start_minute' => $startMinute,
                'end_minute' => $endMinute,
                'rescheduled_by' => $actor->id,
                'rescheduled_at' => now(),
            ]);
        }, attempts: 3));

        return $this->appointments->find($appointmentId, ClinicClock::now());
    }

    /**
     * The start must be one of the doctor's working slots that day (422), and neither the doctor
     * nor the patient may have another scheduled appointment overlapping it (409). Returns the
     * slot's [start, end] in minutes.
     *
     * @return array{0: int, 1: int}
     *
     * @throws ValidationException
     * @throws AppointmentConflictException
     */
    private function takeSlot(bool $hasSchedule, int $doctorId, int $patientId, string $date, string $start, ?int $exceptId = null): array
    {
        $slot = $hasSchedule
            ? collect($this->slots->workingSlots($doctorId, $date, $date))->firstWhere('start', $start)
            : null;

        if ($slot === null) {
            throw ValidationException::withMessages(['start' => __('appointments::messages.slot_unavailable')]);
        }

        $startMinute = ClockTime::toMinutes($slot['start']);
        $endMinute = ClockTime::toMinutes($slot['end']);

        if ($this->appointments->doctorOverlaps($doctorId, $date, $startMinute, $endMinute, $exceptId)) {
            throw new AppointmentConflictException('slot_booked');
        }

        if ($this->appointments->patientOverlaps($patientId, $date, $startMinute, $endMinute, $exceptId)) {
            throw new AppointmentConflictException('patient_busy');
        }

        return [$startMinute, $endMinute];
    }

    /**
     * The unique index on scheduled (doctor, date, start) is the database backstop (research R3);
     * a violation means the slot was taken.
     *
     * @template T
     *
     * @param  Closure(): T  $write
     * @return T
     */
    private function guardSlotUnique(Closure $write): mixed
    {
        try {
            return $write();
        } catch (UniqueConstraintViolationException) {
            throw new AppointmentConflictException('slot_booked');
        }
    }
}
