<?php

namespace App\Modules\Doctors\Http\Resources;

use App\Modules\Doctors\Models\Specialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The one specialty shape, in the catalog and nested in doctors: both names, whatever the request
 * language (FR-004a, clarification Q1).
 *
 * @mixin Specialty
 */
class SpecialtyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_en' => $this->name_en,
            'name_ar' => $this->name_ar,
            'is_active' => $this->is_active,
        ];
    }
}
