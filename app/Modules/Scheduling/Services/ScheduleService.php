<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Repositories\Contracts\DoctorScheduleRepository;
use Illuminate\Support\Facades\DB;

/**
 * Reading, replacing, and clearing a doctor's weekly schedule (US1, research R4).
 */
class ScheduleService
{
    public function __construct(
        private readonly DoctorScheduleRepository $schedules,
        private readonly DoctorRepository $doctors,
    ) {}

    /**
     * The doctor's schedule, or an unsaved empty one, so every response has the same shape (R4).
     *
     * @throws DoctorNotFoundException
     */
    public function show(int $doctorId): DoctorSchedule
    {
        $this->ensureDoctor($doctorId);

        return $this->schedules->findForDoctor($doctorId)
            ?? (new DoctorSchedule(['doctor_id' => $doctorId]))->setRelation('periods', collect());
    }

    /**
     * Replace the whole schedule in one transaction; the schedule row stays locked until the new
     * periods are written (FR-005).
     *
     * @param  list<array{weekday: int, start_minute: int, end_minute: int}>  $periods
     *
     * @throws DoctorNotFoundException
     */
    public function replace(int $doctorId, int $appointmentMinutes, array $periods): DoctorSchedule
    {
        $this->ensureDoctor($doctorId);

        DB::transaction(function () use ($doctorId, $appointmentMinutes, $periods) {
            $schedule = $this->schedules->lockOrCreateForDoctor($doctorId, $appointmentMinutes);
            $this->schedules->update($schedule, ['appointment_minutes' => $appointmentMinutes]);
            $this->schedules->replacePeriods($schedule, $periods);
        });

        return $this->schedules->findForDoctor($doctorId);
    }

    /**
     * Idempotent: a doctor without a schedule stays without one.
     *
     * @throws DoctorNotFoundException
     */
    public function clear(int $doctorId): void
    {
        $this->ensureDoctor($doctorId);

        $this->schedules->deleteForDoctor($doctorId);
    }

    private function ensureDoctor(int $doctorId): void
    {
        $this->doctors->findById($doctorId) ?? throw new DoctorNotFoundException;
    }
}
