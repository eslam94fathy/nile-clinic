<?php

namespace Database\Factories;

use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic charges for tests only (Constitution XII). Defaults to an unpaid charge of 300.00 for a
 * new seen visit. The states set a charge's figures directly and create no payment rows, so a test
 * that checks figures against payments must go through the API.
 *
 * @extends Factory<Charge>
 */
class ChargeFactory extends Factory
{
    protected $model = Charge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'queue_entry_id' => QueueEntry::factory()->seen(),
            'doctor_id' => fn (array $attributes) => QueueEntry::query()->findOrFail($attributes['queue_entry_id'])->doctor_id,
            'patient_id' => fn (array $attributes) => QueueEntry::query()->findOrFail($attributes['queue_entry_id'])->patient_id,
            'date' => fn (array $attributes) => QueueEntry::query()->findOrFail($attributes['queue_entry_id'])->date->toDateString(),
            'status' => ChargeStatus::Unpaid,
            'fee' => '300.00',
            'charged_by' => User::factory()->receptionist(),
        ];
    }

    public function forEntry(QueueEntry $entry): static
    {
        return $this->state(['queue_entry_id' => $entry->id]);
    }

    public function fee(string $amount): static
    {
        return $this->state(['fee' => $amount]);
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ChargeStatus::Paid,
            'amount_paid' => $attributes['fee'],
        ]);
    }

    public function partiallyPaid(string $amount = '100.00'): static
    {
        return $this->state(['status' => ChargeStatus::PartiallyPaid, 'amount_paid' => $amount]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => [
            'status' => ChargeStatus::Cancelled,
            'cancel_reason' => 'Synthetic mistake',
            'cancelled_by' => User::factory()->receptionist(),
            'cancelled_at' => now(),
        ]);
    }
}
