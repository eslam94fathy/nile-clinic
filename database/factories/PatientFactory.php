<?php

namespace Database\Factories;

use App\Modules\Patients\Enums\Sex;
use App\Modules\Patients\Models\Patient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Synthetic patients for tests only (Constitution XII, research R12): fake names in English and
 * Arabic, made-up phones and national IDs.
 *
 * @extends Factory<Patient>
 */
class PatientFactory extends Factory
{
    protected $model = Patient::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        // firstName()/lastName() rather than name(), which adds titles such as "الآنسة".
        $faker = fake()->boolean() ? fake('ar_EG') : fake();

        return [
            'full_name' => $faker->firstName().' '.$faker->lastName(),
            'date_of_birth' => fake()->dateTimeBetween('-90 years', 'now')->format('Y-m-d'),
            'sex' => fake()->randomElement(Sex::cases()),
            'phone' => self::phone(),
            'national_id' => null,
            'address' => null,
            'emergency_contact_name' => null,
            'emergency_contact_relationship' => null,
            'emergency_contact_phone' => null,
        ];
    }

    public function withNationalId(): static
    {
        return $this->state(fn () => ['national_id' => fake()->unique()->numerify('2#############')]);
    }

    public function withAddress(): static
    {
        return $this->state(fn () => ['address' => fake()->streetAddress()]);
    }

    public function withEmergencyContact(): static
    {
        return $this->state(fn () => [
            'emergency_contact_name' => fake()->firstName().' '.fake()->lastName(),
            'emergency_contact_relationship' => fake()->randomElement(['father', 'mother', 'brother', 'sister', 'spouse']),
            'emergency_contact_phone' => self::phone(),
        ]);
    }

    /**
     * A made-up number in the 01XXXXXXXXX shape.
     */
    private static function phone(): string
    {
        return '01'.fake()->numerify('#########');
    }
}
