<?php

namespace App\Modules\Encounters\Http\Resources;

use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a doctor's visits with its record status, never any clinical content (FR-018).
 * `encounter_id` and `encounter_status` come from EncounterRepository::paginateVisits.
 *
 * @mixin QueueEntry
 */
class VisitResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'walk_in' => $this->appointment_id === null,
            'arrived_at' => $this->arrived_at->toISOString(),
            'called_at' => $this->called_at?->toISOString(),
            'finished_at' => $this->finished_at?->toISOString(),
            'record_status' => $this->encounter_status ?? 'none',
            'encounter_id' => $this->encounter_id,
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'sex' => $this->patient->sex->value,
                'date_of_birth' => $this->patient->date_of_birth->toDateString(),
            ],
        ];
    }
}
