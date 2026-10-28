<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full record, including sensitive fields; returned only for a single patient (FR-013).
 *
 * @mixin Patient
 */
class PatientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'full_name' => $this->full_name,
            'sex' => $this->sex->value,
            'date_of_birth' => $this->date_of_birth->toDateString(),
            'phone' => $this->phone,
            'national_id' => $this->national_id,
            'address' => $this->address,
            'emergency_contact' => $this->emergency_contact_name === null ? null : [
                'name' => $this->emergency_contact_name,
                'relationship' => $this->emergency_contact_relationship,
                'phone' => $this->emergency_contact_phone,
            ],
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
