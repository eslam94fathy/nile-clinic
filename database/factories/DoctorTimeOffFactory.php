<?php

namespace Database\Factories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic time off for tests only (Constitution XII, research R10). Defaults to the whole of
 * the clinic's tomorrow.
 *
 * @extends Factory<DoctorTimeOff>
 */
class DoctorTimeOffFactory extends Factory
{
    protected $model = DoctorTimeOff::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $tomorrow = ClinicClock::now()->addDay()->toDateString();

        return [
            'doctor_id' => Doctor::factory(),
            'start_date' => $tomorrow,
            'end_date' => $tomorrow,
            'start_minute' => null,
            'end_minute' => null,
            'reason' => null,
        ];
    }

    /**
     * Part of the day only, e.g. partial('10:00', '12:00').
     */
    public function partial(string $start, string $end): static
    {
        return $this->state([
            'start_minute' => ClockTime::toMinutes($start),
            'end_minute' => ClockTime::toMinutes($end),
        ]);
    }

    public function withReason(): static
    {
        return $this->state(['reason' => 'Synthetic leave']);
    }
}
