<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Doctor;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The doctor list shape (FR-015): never the account's user id, email, or role.
 *
 * @mixin Doctor
 */
class DoctorSummaryResource extends JsonResource
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
        ];
    }
}
