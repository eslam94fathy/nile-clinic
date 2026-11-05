<?php

namespace Database\Factories;

use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic addenda for tests only (Constitution XII).
 *
 * @extends Factory<EncounterAddendum>
 */
class EncounterAddendumFactory extends Factory
{
    protected $model = EncounterAddendum::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'encounter_id' => Encounter::factory()->finalized(),
            'text' => 'Synthetic correction',
        ];
    }
}
