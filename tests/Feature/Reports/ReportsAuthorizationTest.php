<?php

namespace Tests\Feature\Reports;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every role against every report: only active administrators get through (SC-006, FR-001, FR-020).
 */
class ReportsAuthorizationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private const PATHS = [
        'money collected' => '/api/v1/reports/money-collected',
        'billing' => '/api/v1/reports/billing',
        'activity' => '/api/v1/reports/activity',
        'times' => '/api/v1/reports/times',
    ];

    private const VALID = '?from=2026-11-01&to=2026-11-01';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function reports(): array
    {
        return array_map(fn (string $path) => [$path], self::PATHS);
    }

    /**
     * @return array<string, array{string, Role}>
     */
    public static function refusedRoles(): array
    {
        $cases = [];

        foreach (self::PATHS as $name => $path) {
            foreach ([Role::Receptionist, Role::Doctor] as $role) {
                $cases["{$name} as {$role->value}"] = [$path, $role];
            }
        }

        return $cases;
    }

    #[DataProvider('reports')]
    public function test_an_administrator_succeeds(string $path): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->getJson($path.self::VALID)->assertOk();
    }

    #[DataProvider('refusedRoles')]
    public function test_other_roles_are_forbidden(string $path, Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson($path.self::VALID)->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);
    }

    #[DataProvider('refusedRoles')]
    public function test_other_roles_are_forbidden_before_validation_and_lookup(string $path, Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson($path)->assertForbidden();
        $this->getJson("{$path}?from=not-a-date&to=2026-11-01")->assertForbidden();
        $this->getJson($path.self::VALID.'&doctor_id=999999')->assertForbidden();
    }

    #[DataProvider('reports')]
    public function test_no_token_is_unauthenticated(string $path): void
    {
        $this->getJson($path.self::VALID)->assertUnauthorized();
    }

    #[DataProvider('reports')]
    public function test_a_deactivated_account_with_a_real_token_is_unauthenticated(string $path): void
    {
        $administrator = User::factory()->administrator()->create();
        $token = $this->signIn($administrator);
        $administrator->update(['is_active' => false]);

        $this->usingToken($token);

        $this->getJson($path.self::VALID)->assertUnauthorized();
    }
}
