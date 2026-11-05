<?php

namespace App\Modules\Encounters\Http\Resources;

use App\Modules\Encounters\Models\Encounter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One full medical record, the only place clinical content is returned (FR-018). Only the patient
 * summary is shown: never the national ID, address, phone, or emergency contact (FR-015).
 *
 * @mixin Encounter
 */
class EncounterResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'visit_id' => $this->queue_entry_id,
            'status' => $this->status->value,
            'date' => $this->date->toDateString(),
            'doctor' => [
                'id' => $this->doctor->id,
                'name' => $this->doctor->user->name,
                'title' => $this->doctor->title,
            ],
            'patient' => [
                'id' => $this->patient->id,
                'full_name' => $this->patient->full_name,
                'sex' => $this->patient->sex->value,
                'date_of_birth' => $this->patient->date_of_birth->toDateString(),
            ],
            'chief_complaint' => $this->chief_complaint,
            'examination_findings' => $this->examination_findings,
            'diagnosis' => $this->diagnosis,
            'treatment_plan' => $this->treatment_plan,
            'prescriptions' => $this->prescriptions ?? [],
            'addenda' => AddendumResource::collection($this->addenda),
            'started_at' => $this->created_at->toISOString(),
            'last_saved_at' => $this->updated_at->toISOString(),
            'finalized_at' => $this->finalized_at?->toISOString(),
        ];
    }
}
