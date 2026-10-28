<?php

namespace App\Modules\Scheduling\Repositories\Contracts;

use App\Modules\Scheduling\Models\DoctorSchedule;

interface DoctorScheduleRepository
{
    /**
     * The doctor's schedule with its periods (ordered by weekday, then start), or null.
     */
    public function findForDoctor(int $doctorId): ?DoctorSchedule;

    /**
     * Make sure the doctor has a schedule row and lock it until the caller's transaction ends, so
     * two administrators saving at once are serialized (research R4).
     */
    public function lockOrCreateForDoctor(int $doctorId, int $appointmentMinutes): DoctorSchedule;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(DoctorSchedule $schedule, array $attributes): DoctorSchedule;

    /**
     * Make these the schedule's only periods, and mark the schedule as changed now.
     *
     * @param  list<array{weekday: int, start_minute: int, end_minute: int}>  $periods
     */
    public function replacePeriods(DoctorSchedule $schedule, array $periods): void;

    /**
     * Delete the doctor's schedule and its periods, if any.
     */
    public function deleteForDoctor(int $doctorId): void;
}
