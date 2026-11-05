<?php

namespace App\Modules\Encounters\Http\Resources;

use App\Modules\Doctors\Http\Resources\SpecialtyResource;
use App\Modules\Encounters\Models\Encounter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One record in a patient's history: when and by whom, never any clinical content (US4-1, FR-018).
 *
 * @mixin Encounter
 */
class EncounterHistoryItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date->toDateString(),
            'doctor' => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->user->name,
                'specialties' => SpecialtyResource::collection($this->doctor->specialties),
            ],
        ];
    }
}
