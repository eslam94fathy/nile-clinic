<?php

namespace App\Modules\Patients\Http\Resources;

use App\Modules\Patients\Models\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The list and search shape (FR-013): never the national ID, address, or emergency contact.
 *
 * @mixin Patient
 */
class PatientSummaryResource extends JsonResource
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
        ];
    }
}
