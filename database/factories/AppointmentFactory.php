<?php

namespace Database\Factories;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic appointments for tests only (Constitution XII). Defaults to a scheduled 09:00–09:20
 * appointment on the clinic's tomorrow. Rows are written directly, without the booking checks.
 *
 * @extends Factory<Appointment>
 */
class AppointmentFactory extends Factory
{
    protected $model = Appointment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'doctor_id' => Doctor::factory(),
            'date' => ClinicClock::now()->addDay()->toDateString(),
            'start_minute' => 540,
            'end_minute' => 560,
            'status' => AppointmentStatus::Scheduled,
            'note' => null,
            'booked_by' => User::factory()->receptionist(),
        ];
    }

    /**
     * On this date and time, e.g. at('2026-11-01', '09:20') or at('2026-11-01', '09:20', 30).
     */
    public function at(string $date, string $start, int $minutes = 20): static
    {
        return $this->state([
            'date' => $date,
            'start_minute' => ClockTime::toMinutes($start),
            'end_minute' => ClockTime::toMinutes($start) + $minutes,
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::Cancelled,
            'status_changed_by' => User::factory()->receptionist(),
            'status_changed_at' => now(),
            'cancellation_reason' => 'Synthetic reason',
        ]);
    }

    public function noShow(): static
    {
        return $this->state(fn () => [
            'status' => AppointmentStatus::NoShow,
            'status_changed_by' => User::factory()->receptionist(),
            'status_changed_at' => now(),
        ]);
    }
}
