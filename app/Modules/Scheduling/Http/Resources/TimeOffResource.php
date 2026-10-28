<?php

namespace App\Modules\Scheduling\Http\Resources;

use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A time off entry in clinic wall-clock time; both times are null for whole days (FR-013).
 *
 * @mixin DoctorTimeOff
 */
class TimeOffResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'start_date' => $this->start_date->toDateString(),
            'end_date' => $this->end_date->toDateString(),
            'start_time' => $this->start_minute === null ? null : ClockTime::fromMinutes($this->start_minute),
            'end_time' => $this->end_minute === null ? null : ClockTime::fromMinutes($this->end_minute),
            'reason' => $this->reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
