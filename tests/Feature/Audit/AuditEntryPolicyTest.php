<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Only an active administrator may search the trail (FR-013).
 */
class AuditEntryPolicyTest extends TestCase
{
    /**
     * @return array<string, array{Role, bool}>
     */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator, true],
            'receptionist' => [Role::Receptionist, false],
            'doctor' => [Role::Doctor, false],
        ];
    }

    #[DataProvider('roles')]
    public function test_each_role_gets_exactly_its_ability(Role $role, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows('viewAny', AuditEntry::class));
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_are_refused(Role $role, bool $allowedWhenActive): void
    {
        unset($allowedWhenActive);

        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows('viewAny', AuditEntry::class));
    }
}
