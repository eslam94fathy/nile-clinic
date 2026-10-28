<?php

namespace Database\Factories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Scheduling\Support\Weekday;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic weekly schedules for tests only (Constitution XII, research R10).
 *
 * @extends Factory<DoctorSchedule>
 */
class DoctorScheduleFactory extends Factory
{
    protected $model = DoctorSchedule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'appointment_minutes' => 20,
        ];
    }

    /**
     * Add one working period, e.g. withPeriod('sunday', '09:00', '13:00').
     */
    public function withPeriod(string $day, string $start, string $end): static
    {
        return $this->afterCreating(fn (DoctorSchedule $schedule) => $schedule->periods()->create([
            'weekday' => Weekday::fromName($day)->value,
            'start_minute' => ClockTime::toMinutes($start),
            'end_minute' => ClockTime::toMinutes($end),
        ]));
    }

    /**
     * Sunday to Thursday, 09:00–13:00 and 17:00–21:00.
     */
    public function weekdays(): static
    {
        $factory = $this;

        foreach (['sunday', 'monday', 'tuesday', 'wednesday', 'thursday'] as $day) {
            $factory = $factory->withPeriod($day, '09:00', '13:00')->withPeriod($day, '17:00', '21:00');
        }

        return $factory;
    }
}
