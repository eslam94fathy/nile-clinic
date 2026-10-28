<?php

namespace App\Modules\Scheduling\Repositories;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Repositories\Contracts\DoctorScheduleRepository;

class EloquentDoctorScheduleRepository implements DoctorScheduleRepository
{
    public function findForDoctor(int $doctorId): ?DoctorSchedule
    {
        return DoctorSchedule::query()->with('periods')->where('doctor_id', $doctorId)->first();
    }

    public function lockOrCreateForDoctor(int $doctorId, int $appointmentMinutes): DoctorSchedule
    {
        $now = now();

        DoctorSchedule::query()->insertOrIgnore([
            'doctor_id' => $doctorId,
            'appointment_minutes' => $appointmentMinutes,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return DoctorSchedule::query()->where('doctor_id', $doctorId)->lockForUpdate()->firstOrFail();
    }

    public function update(DoctorSchedule $schedule, array $attributes): DoctorSchedule
    {
        $schedule->fill($attributes)->save();

        return $schedule;
    }

    public function replacePeriods(DoctorSchedule $schedule, array $periods): void
    {
        $schedule->periods()->delete();
        $schedule->periods()->createMany($periods);
        $schedule->touch();
    }

    public function deleteForDoctor(int $doctorId): void
    {
        DoctorSchedule::query()->where('doctor_id', $doctorId)->delete();
    }
}
