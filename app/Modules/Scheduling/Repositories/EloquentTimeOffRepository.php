<?php

namespace App\Modules\Scheduling\Repositories;

use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Repositories\Contracts\TimeOffRepository;
use Illuminate\Support\Collection;

class EloquentTimeOffRepository implements TimeOffRepository
{
    public function overlapping(int $doctorId, string $from, string $to): Collection
    {
        return DoctorTimeOff::query()
            ->where('doctor_id', $doctorId)
            ->where('start_date', '<=', $to)
            ->where('end_date', '>=', $from)
            ->orderBy('start_date')
            ->orderBy('start_minute')
            ->orderBy('id')
            ->get();
    }
}
