<?php

namespace Database\Factories;

use App\Modules\Doctors\Models\Specialty;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic specialties for tests only (Constitution XII, research R12): numbered names, so two
 * factory specialties never collide on the unique name indexes.
 *
 * @extends Factory<Specialty>
 */
class SpecialtyFactory extends Factory
{
    protected $model = Specialty::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $number = fake()->unique()->numberBetween(1, 1000000);

        return [
            'name_en' => 'Specialty '.$number,
            'name_ar' => 'تخصص '.$number,
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
