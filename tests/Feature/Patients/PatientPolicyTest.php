<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only active administrators and receptionists may work with patients; doctors may not
 * (FR-014, FR-015, clarification Q1).
 */
class PatientPolicyTest extends TestCase
{
    private const ABILITIES = ['viewAny', 'view', 'create', 'update'];

    /**
     * @return array<string, array{Role, string, bool}>
     */
    public static function roleAbilities(): array
    {
        $cases = [];

        foreach (Role::cases() as $role) {
            foreach (self::ABILITIES as $ability) {
                $cases["{$role->value} {$ability}"] = [$role, $ability, $role !== Role::Doctor];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{Role, string}>
     */
    public static function inactiveRoleAbilities(): array
    {
        $cases = [];

        foreach ([Role::Administrator, Role::Receptionist] as $role) {
            foreach (self::ABILITIES as $ability) {
                $cases["inactive {$role->value} {$ability}"] = [$role, $ability];
            }
        }

        return $cases;
    }

    #[DataProvider('roleAbilities')]
    public function test_only_administrators_and_receptionists_may_work_with_patients(Role $role, string $ability, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, Patient::class));
    }

    #[DataProvider('inactiveRoleAbilities')]
    public function test_inactive_accounts_are_refused(Role $role, string $ability): void
    {
        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows($ability, Patient::class));
    }
}
