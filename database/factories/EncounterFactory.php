<?php

namespace Database\Factories;

use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic medical records for tests only (Constitution XII). Defaults to an empty draft of a new
 * seen visit.
 *
 * @extends Factory<Encounter>
 */
class EncounterFactory extends Factory
{
    protected $model = Encounter::class;

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
            'status' => EncounterStatus::Draft,
        ];
    }

    public function forEntry(QueueEntry $entry): static
    {
        return $this->state(['queue_entry_id' => $entry->id]);
    }

    /**
     * All content filled in; still a draft.
     */
    public function complete(): static
    {
        return $this->state(fn () => [
            'chief_complaint' => 'Synthetic complaint',
            'examination_findings' => 'Synthetic findings',
            'diagnosis' => 'Synthetic diagnosis',
            'treatment_plan' => 'Synthetic plan',
            'prescriptions' => [[
                'medication' => 'Synthetic medication',
                'dosage' => 'Synthetic dosage',
                'frequency' => 'Synthetic frequency',
                'duration' => 'Synthetic duration',
                'instructions' => 'Synthetic instructions',
            ]],
        ]);
    }

    public function finalized(): static
    {
        return $this->complete()->state(fn () => [
            'status' => EncounterStatus::Finalized,
            'finalized_at' => now(),
        ]);
    }
}
