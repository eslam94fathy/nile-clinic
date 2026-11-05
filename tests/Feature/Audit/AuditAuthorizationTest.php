<?php

namespace Tests\Feature\Audit;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SC-005, FR-013, FR-016: only an active administrator may search the trail.
 */
class AuditAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/audit-entries';

    public function test_an_administrator_may_search(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->getJson(self::URI)->assertOk();
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function otherRoles(): array
    {
        return ['receptionist' => [Role::Receptionist], 'doctor' => [Role::Doctor]];
    }

    #[DataProvider('otherRoles')]
    public function test_other_roles_are_forbidden(Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson(self::URI)->assertForbidden()->assertExactJson(['message' => __('auth.forbidden')]);
    }

    #[DataProvider('otherRoles')]
    public function test_refusal_comes_before_validation(Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson(self::URI.'?from=bad&cursor=bad&per_page=0')->assertForbidden();
    }

    public function test_no_token_is_unauthenticated(): void
    {
        $this->getJson(self::URI)->assertUnauthorized()->assertExactJson(['message' => __('auth.unauthenticated')]);
    }

    public function test_a_deactivated_accounts_token_is_unauthenticated(): void
    {
        $user = User::factory()->administrator()->create();
        $token = $user->createToken('synthetic')->plainTextToken;
        $user->update(['is_active' => false]);

        $this->withToken($token)->getJson(self::URI)->assertUnauthorized();
    }
}
