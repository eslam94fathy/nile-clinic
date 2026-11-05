<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Services\AuditTrail;
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
        private readonly AuditTrail $audit,
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
            $periodsChanged = $this->periodsDiffer($schedule, $periods);
            $this->schedules->update($schedule, ['appointment_minutes' => $appointmentMinutes]);

            // Before replacePeriods: it touches the schedule, which wipes the model's changes (research R4).
            $this->audit->recordChanges(AuditAction::ScheduleSet, $schedule, AuditSubject::Doctor, extraFields: $periodsChanged ? ['periods'] : [], subjectId: $doctorId);

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

        DB::transaction(function () use ($doctorId) {
            if ($this->schedules->lockForDoctor($doctorId) === null) {
                return;
            }

            $this->schedules->deleteForDoctor($doctorId);

            $this->audit->record(AuditAction::ScheduleCleared, AuditSubject::Doctor, $doctorId);
        });
    }

    /**
     * @param  list<array{weekday: int, start_minute: int, end_minute: int}>  $periods
     */
    private function periodsDiffer(DoctorSchedule $schedule, array $periods): bool
    {
        $normalize = function (iterable $rows): array {
            $normalized = [];

            foreach ($rows as $row) {
                $normalized[] = [(int) $row['weekday'], (int) $row['start_minute'], (int) $row['end_minute']];
            }

            sort($normalized);

            return $normalized;
        };

        return $normalize($schedule->periods) !== $normalize($periods);
    }

    private function ensureDoctor(int $doctorId): void
    {
        $this->doctors->findById($doctorId) ?? throw new DoctorNotFoundException;
    }
}
