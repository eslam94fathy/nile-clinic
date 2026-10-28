<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every active staff member may read specialties and doctors; only active administrators may
 * change them; only active doctors may fetch their own profile (research R9, FR-003, FR-010,
 * FR-017, clarification Q3).
 */
class DoctorsPolicyTest extends TestCase
{
    /**
     * Ability => roles allowed, per model class.
     */
    private const RULES = [
        Specialty::class => [
            'viewAny' => [Role::Administrator, Role::Receptionist, Role::Doctor],
            'create' => [Role::Administrator],
            'update' => [Role::Administrator],
            'deactivate' => [Role::Administrator],
            'reactivate' => [Role::Administrator],
        ],
        Doctor::class => [
            'viewAny' => [Role::Administrator, Role::Receptionist, Role::Doctor],
            'view' => [Role::Administrator, Role::Receptionist, Role::Doctor],
            'create' => [Role::Administrator],
            'update' => [Role::Administrator],
            'viewOwn' => [Role::Doctor],
        ],
    ];

    /**
     * @return array<string, array{Role, class-string, string, bool}>
     */
    public static function roleAbilities(): array
    {
        $cases = [];

        foreach (self::RULES as $model => $abilities) {
            foreach ($abilities as $ability => $allowedRoles) {
                foreach (Role::cases() as $role) {
                    $cases[class_basename($model)." {$ability} {$role->value}"] = [
                        $role, $model, $ability, in_array($role, $allowedRoles, true),
                    ];
                }
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{Role, class-string, string}>
     */
    public static function inactiveRoleAbilities(): array
    {
        $cases = [];

        foreach (self::RULES as $model => $abilities) {
            foreach (array_keys($abilities) as $ability) {
                foreach (Role::cases() as $role) {
                    $cases['inactive '.class_basename($model)." {$ability} {$role->value}"] = [$role, $model, $ability];
                }
            }
        }

        return $cases;
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('roleAbilities')]
    public function test_each_role_gets_exactly_its_abilities(Role $role, string $model, string $ability, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, $model));
    }

    /**
     * @param  class-string  $model
     */
    #[DataProvider('inactiveRoleAbilities')]
    public function test_inactive_accounts_are_refused(Role $role, string $model, string $ability): void
    {
        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows($ability, $model));
    }
}
