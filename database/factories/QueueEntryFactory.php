<?php

namespace Database\Factories;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic queue entries for tests only (Constitution XII). Defaults to a waiting walk-in with
 * number 1 on the clinic's today.
 *
 * Writes entries without touching queue_days: never mix factory-made entries and API check-ins or
 * walk-ins for the same doctor in one test, or queue numbers can collide.
 *
 * @extends Factory<QueueEntry>
 */
class QueueEntryFactory extends Factory
{
    protected $model = QueueEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'patient_id' => Patient::factory(),
            'appointment_id' => null,
            'date' => ClinicClock::today(),
            'queue_number' => 1,
            'order_minute' => 540,
            'status' => QueueStatus::Waiting,
            'arrived_at' => now(),
            'checked_in_by' => User::factory()->receptionist(),
        ];
    }

    public function forAppointment(Appointment $appointment): static
    {
        return $this->state([
            'doctor_id' => $appointment->doctor_id,
            'patient_id' => $appointment->patient_id,
            'appointment_id' => $appointment->id,
            'date' => $appointment->date->toDateString(),
            'order_minute' => $appointment->start_minute,
        ]);
    }

    public function number(int $number): static
    {
        return $this->state(['queue_number' => $number]);
    }

    public function withDoctor(): static
    {
        return $this->state(fn () => [
            'status' => QueueStatus::WithDoctor,
            'called_at' => now(),
        ]);
    }

    public function seen(): static
    {
        return $this->state(fn () => [
            'status' => QueueStatus::Seen,
            'called_at' => now(),
            'finished_at' => now(),
        ]);
    }

    public function left(): static
    {
        return $this->state(fn () => [
            'status' => QueueStatus::Left,
            'left_at' => now(),
            'left_by' => User::factory()->receptionist(),
        ]);
    }
}
