<?php

namespace Tests\Feature\Queues;

use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Active administrators and receptionists manage the queues; active doctors act only on their own
 * list (research R7).
 */
class QueuesPolicyTest extends TestCase
{
    /**
     * Ability => roles allowed.
     */
    private const RULES = [
        'viewAny' => [Role::Administrator, Role::Receptionist],
        'create' => [Role::Administrator, Role::Receptionist],
        'update' => [Role::Administrator, Role::Receptionist],
        'viewOwn' => [Role::Doctor],
        'updateOwn' => [Role::Doctor],
    ];

    /**
     * @return array<string, array{Role, string, bool}>
     */
    public static function roleAbilities(): array
    {
        $cases = [];

        foreach (self::RULES as $ability => $allowedRoles) {
            foreach (Role::cases() as $role) {
                $cases["{$ability} {$role->value}"] = [$role, $ability, in_array($role, $allowedRoles, true)];
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

        foreach (array_keys(self::RULES) as $ability) {
            foreach (Role::cases() as $role) {
                $cases["inactive {$ability} {$role->value}"] = [$role, $ability];
            }
        }

        return $cases;
    }

    #[DataProvider('roleAbilities')]
    public function test_each_role_gets_exactly_its_abilities(Role $role, string $ability, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, QueueEntry::class));
    }

    #[DataProvider('inactiveRoleAbilities')]
    public function test_inactive_accounts_are_refused(Role $role, string $ability): void
    {
        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows($ability, QueueEntry::class));
    }
}
