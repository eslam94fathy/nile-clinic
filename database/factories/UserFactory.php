<?php

namespace Database\Factories;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Synthetic staff accounts for tests only (Constitution XII): fake names, @clinic.test emails.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    /**
     * The default plain-text password for factory accounts.
     */
    public const PASSWORD = 'password-for-tests';

    /**
     * Hashed once and reused, so tests do not pay the hashing cost per account.
     */
    protected static ?string $passwordHash = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => strtolower(fake()->unique()->userName()).'@clinic.test',
            'password' => static::$passwordHash ??= Hash::make(self::PASSWORD),
            'role' => Role::Receptionist,
            'is_active' => true,
        ];
    }

    public function administrator(): static
    {
        return $this->state(['role' => Role::Administrator]);
    }

    public function receptionist(): static
    {
        return $this->state(['role' => Role::Receptionist]);
    }

    public function doctor(): static
    {
        return $this->state(['role' => Role::Doctor]);
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }
}
