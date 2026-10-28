<?php

namespace App\Modules\Scheduling\Services;

use App\Modules\Doctors\Exceptions\DoctorNotFoundException;
use App\Modules\Doctors\Repositories\Contracts\DoctorRepository;
use App\Modules\Scheduling\Exceptions\TimeOffNotFoundException;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Repositories\Contracts\TimeOffRepository;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Listing, recording, and removing a doctor's time off (US3, research R7).
 */
class TimeOffService
{
    public function __construct(
        private readonly DoctorRepository $doctors,
        private readonly TimeOffRepository $timeOff,
    ) {}

    /**
     * @return Collection<int, DoctorTimeOff>
     *
     * @throws DoctorNotFoundException
     */
    public function list(int $doctorId, string $from, string $to): Collection
    {
        $this->ensureDoctor($doctorId);

        return $this->timeOff->overlapping($doctorId, $from, $to);
    }

    /**
     * @param  array{start_date: string, end_date: string, start_time?: string|null, end_time?: string|null, reason?: string|null}  $attributes
     *
     * @throws DoctorNotFoundException
     */
    public function record(int $doctorId, array $attributes): DoctorTimeOff
    {
        $this->ensureDoctor($doctorId);

        return $this->timeOff->create([
            'doctor_id' => $doctorId,
            'start_date' => $attributes['start_date'],
            'end_date' => $attributes['end_date'],
            'start_minute' => isset($attributes['start_time']) ? ClockTime::toMinutes($attributes['start_time']) : null,
            'end_minute' => isset($attributes['end_time']) ? ClockTime::toMinutes($attributes['end_time']) : null,
            'reason' => $attributes['reason'] ?? null,
        ]);
    }

    /**
     * Remove time off that has not ended; ended time off stays on record (FR-017).
     *
     * @throws DoctorNotFoundException
     * @throws TimeOffNotFoundException
     */
    public function remove(int $doctorId, int $timeOffId): void
    {
        $this->ensureDoctor($doctorId);

        $entry = $this->timeOff->findForDoctor($doctorId, $timeOffId) ?? throw new TimeOffNotFoundException;

        $ends = CarbonImmutable::parse($entry->end_date->toDateString(), ClinicClock::timezone())
            ->addMinutes($entry->end_minute ?? 24 * 60);

        if ($ends->lessThanOrEqualTo(ClinicClock::now())) {
            throw ValidationException::withMessages([
                'time_off' => __('scheduling::messages.time_off_ended'),
            ]);
        }

        $this->timeOff->delete($entry);
    }

    private function ensureDoctor(int $doctorId): void
    {
        $this->doctors->findById($doctorId) ?? throw new DoctorNotFoundException;
    }
}
