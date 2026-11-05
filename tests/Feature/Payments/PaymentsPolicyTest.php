<?php

namespace Tests\Feature\Payments;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reception handles charges and payments; only administrators set fees, discount, and refund; doctors
 * do nothing here (research R9).
 */
class PaymentsPolicyTest extends TestCase
{
    /**
     * [model, ability] => roles allowed.
     *
     * @return list<array{class-string, string, list<Role>}>
     */
    private static function rules(): array
    {
        $staff = [Role::Administrator, Role::Receptionist];
        $admin = [Role::Administrator];

        return [
            [DoctorFee::class, 'viewAny', $staff],
            [DoctorFee::class, 'set', $admin],
            [Charge::class, 'viewAny', $staff],
            [Charge::class, 'create', $staff],
            [Charge::class, 'pay', $staff],
            [Charge::class, 'cancel', $staff],
            [Charge::class, 'discount', $admin],
            [Charge::class, 'refund', $admin],
        ];
    }

    /**
     * @return array<string, array{Role, class-string, string, bool}>
     */
    public static function roleAbilities(): array
    {
        $cases = [];

        foreach (self::rules() as [$model, $ability, $allowedRoles]) {
            foreach (Role::cases() as $role) {
                $cases[class_basename($model)." {$ability} {$role->value}"] = [$role, $model, $ability, in_array($role, $allowedRoles, true)];
            }
        }

        return $cases;
    }

    #[DataProvider('roleAbilities')]
    public function test_each_role_gets_exactly_its_abilities(Role $role, string $model, string $ability, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, $model));
    }

    #[DataProvider('roleAbilities')]
    public function test_inactive_accounts_are_refused(Role $role, string $model, string $ability, bool $allowedWhenActive): void
    {
        unset($allowedWhenActive);

        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows($ability, $model));
    }
}
