<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only active administrators may manage staff accounts (US2, FR-012–FR-014).
 */
class StaffMemberPolicyTest extends TestCase
{
    private const ABILITIES = ['viewAny', 'view', 'create', 'update', 'deactivate', 'reactivate', 'setPassword'];

    /**
     * @return array<string, array{Role, string, bool}>
     */
    public static function roleAbilities(): array
    {
        $cases = [];

        foreach (Role::cases() as $role) {
            foreach (self::ABILITIES as $ability) {
                $cases["{$role->value} {$ability}"] = [$role, $ability, $role === Role::Administrator];
            }
        }

        return $cases;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function abilities(): array
    {
        return array_combine(self::ABILITIES, array_map(fn (string $ability) => [$ability], self::ABILITIES));
    }

    #[DataProvider('roleAbilities')]
    public function test_only_administrators_may_manage_staff(Role $role, string $ability, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows($ability, User::class));
    }

    #[DataProvider('abilities')]
    public function test_inactive_administrators_are_refused(string $ability): void
    {
        $actor = User::factory()->administrator()->inactive()->make();

        $this->assertFalse(Gate::forUser($actor)->allows($ability, User::class));
    }
}
