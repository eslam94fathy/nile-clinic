<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A single doctor profile (FR-016): the list shape plus the registration number and timestamps.
 * Never the account's user id, email, or role (FR-015).
 *
 * @mixin Doctor
 */
class DoctorResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->user->name,
            'title' => $this->title,
            'is_active' => $this->isActive(),
            'specialties' => SpecialtyResource::collection($this->specialties),
            'registration_number' => $this->registration_number,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
