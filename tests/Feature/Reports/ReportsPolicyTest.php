<?php

namespace Tests\Feature\Reports;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reports are for active administrators only (FR-001, research R9).
 */
class ReportsPolicyTest extends TestCase
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
    public function test_only_administrators_may_view_reports(Role $role, bool $allowed): void
    {
        $actor = User::factory()->make(['role' => $role]);

        $this->assertSame($allowed, Gate::forUser($actor)->allows('view-reports'));
    }

    #[DataProvider('roles')]
    public function test_inactive_accounts_are_refused(Role $role, bool $allowedWhenActive): void
    {
        unset($allowedWhenActive);

        $actor = User::factory()->inactive()->make(['role' => $role]);

        $this->assertFalse(Gate::forUser($actor)->allows('view-reports'));
    }
}
