<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Models\SchedulePeriod;
use App\Modules\Scheduling\Repositories\Contracts\DoctorScheduleRepository;
use App\Modules\Scheduling\Repositories\Contracts\TimeOffRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\SlotCalculator;

/**
 * A doctor's bookable slots for a date range, computed on request (US2, research R6).
 */
class SlotService
{
    public function __construct(
        private readonly DoctorRepository $doctors,
        private readonly DoctorScheduleRepository $schedules,
        private readonly TimeOffRepository $timeOff,
    ) {}

    /**
     * Empty for an inactive doctor (feature 004, FR-012) or one without a schedule (FR-009).
     *
     * @param  string  $from  Y-m-d
     * @param  string  $to  Y-m-d, inclusive
     * @return list<array{date: string, start: string, end: string}>
     *
     * @throws DoctorNotFoundException
     */
    public function forDoctor(int $doctorId, string $from, string $to): array
    {
        $doctor = $this->doctors->findById($doctorId) ?? throw new DoctorNotFoundException;
        $schedule = $this->schedules->findForDoctor($doctorId);

        if (! $doctor->isActive() || $schedule === null) {
            return [];
        }

        $periodsByWeekday = [];
        foreach ($schedule->periods as $period) {
            /** @var SchedulePeriod $period */
            $periodsByWeekday[$period->weekday][] = [$period->start_minute, $period->end_minute];
        }

        $timeOff = $this->timeOff->overlapping($doctorId, $from, $to)->map(fn (DoctorTimeOff $entry) => [
            'start_date' => $entry->start_date->toDateString(),
            'end_date' => $entry->end_date->toDateString(),
            'start_minute' => $entry->start_minute,
            'end_minute' => $entry->end_minute,
        ])->all();

        return SlotCalculator::slots($periodsByWeekday, $schedule->appointment_minutes, $timeOff, $from, $to, ClinicClock::now());
    }
}
