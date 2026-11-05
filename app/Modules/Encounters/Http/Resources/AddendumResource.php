<?php

namespace App\Modules\Encounters\Http\Resources;

use App\Modules\Encounters\Models\EncounterAddendum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin EncounterAddendum
 */
class AddendumResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'text' => $this->text,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
