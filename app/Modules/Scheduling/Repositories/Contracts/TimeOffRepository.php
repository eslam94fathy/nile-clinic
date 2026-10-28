<?php

namespace App\Modules\Scheduling\Repositories\Contracts;

use App\Modules\Scheduling\Models\DoctorTimeOff;
use Illuminate\Support\Collection;

interface TimeOffRepository
{
    /**
     * The doctor's time off that overlaps the dates (inclusive), ordered by start date, start
     * time (whole days first), then id.
     *
     * @return Collection<int, DoctorTimeOff>
     */
    public function overlapping(int $doctorId, string $from, string $to): Collection;
}
