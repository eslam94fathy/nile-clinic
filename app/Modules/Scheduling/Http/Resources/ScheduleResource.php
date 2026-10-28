<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\SchedulePeriod;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Scheduling\Support\Weekday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A weekly schedule: always all seven days, sunday … saturday, each with its periods in time
 * order; a day without periods is a day off (FR-007). An unsaved schedule (none set) has a null
 * appointment length and updated_at.
 *
 * @mixin DoctorSchedule
 */
class ScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $periods = $this->periods->sortBy('start_minute')->groupBy('weekday');

        return [
            'doctor_id' => $this->doctor_id,
            'appointment_minutes' => $this->appointment_minutes,
            'days' => array_map(fn (Weekday $day) => [
                'day' => $day->name(),
                'periods' => $periods->get($day->value, collect())->map(fn (SchedulePeriod $period) => [
                    'start' => ClockTime::fromMinutes($period->start_minute),
                    'end' => ClockTime::fromMinutes($period->end_minute),
                ])->values()->all(),
            ], Weekday::cases()),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
