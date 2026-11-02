<?php

namespace App\Modules\Scheduling\Repositories\Contracts;

/**
 * Times a doctor is already booked (feature 006, research R2, approved D1). Declared by
 * Scheduling, implemented and bound by the Appointments module, so the dependency goes one way:
 * Appointments → Scheduling.
 */
interface BookedTimeRepository
{
    /**
     * The doctor's times already taken by scheduled appointments on these dates.
     *
     * @param  string  $from  Y-m-d
     * @param  string  $to  Y-m-d, inclusive
     * @return list<array{date: string, start_minute: int, end_minute: int}>
     */
    public function forDoctor(int $doctorId, string $from, string $to): array;
}
