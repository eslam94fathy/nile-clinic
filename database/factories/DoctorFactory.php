<?php

namespace Database\Factories;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic doctor profiles for tests only (Constitution XII, research R12), each linked to a new
 * doctor-role factory account.
 *
 * @extends Factory<Doctor>
 */
class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory()->doctor(),
            'title' => fake()->randomElement(['Consultant', 'Specialist', 'Resident']),
            'registration_number' => null,
        ];
    }

    public function withRegistrationNumber(): static
    {
        return $this->state(fn () => ['registration_number' => strtoupper(fake()->unique()->bothify('??#####'))]);
    }

    public function withSpecialties(int $count = 1): static
    {
        return $this->afterCreating(fn (Doctor $doctor) => $doctor->specialties()->attach(
            Specialty::factory()->count($count)->create()->modelKeys(),
        ));
    }
}
