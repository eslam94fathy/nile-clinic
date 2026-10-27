<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every staff-management endpoint refuses receptionists and doctors and changes nothing (SC-002).
 */
class AuthorizationMatrixTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string, array<string, string>}>
     */
    public static function endpoints(): array
    {
        $password = ['password' => 'brand-new-pass-1', 'password_confirmation' => 'brand-new-pass-1'];

        return [
            'list' => ['GET', '/api/v1/staff', []],
            'create' => ['POST', '/api/v1/staff', ['name' => 'New Member', 'email' => 'new@clinic.test', 'role' => 'doctor'] + $password],
            'show' => ['GET', '/api/v1/staff/{id}', []],
            'update' => ['PATCH', '/api/v1/staff/{id}', ['name' => 'Changed Name']],
            'deactivate' => ['POST', '/api/v1/staff/{id}/deactivate', []],
            'reactivate' => ['POST', '/api/v1/staff/{id}/reactivate', []],
            'set password' => ['PUT', '/api/v1/staff/{id}/password', $password],
        ];
    }

    /**
     * @return array<string, array{Role, string, string, array<string, string>}>
     */
    public static function refusedRolesAndEndpoints(): array
    {
        $cases = [];

        foreach ([Role::Receptionist, Role::Doctor] as $role) {
            foreach (self::endpoints() as $name => $endpoint) {
                $cases["{$role->value} {$name}"] = [$role, ...$endpoint];
            }
        }

        return $cases;
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('refusedRolesAndEndpoints')]
    public function test_receptionists_and_doctors_are_forbidden_and_nothing_changes(Role $role, string $method, string $uri, array $body): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->create(['role' => $role]));
        $before = User::query()->orderBy('id')->get()->makeVisible('password')->toArray();

        $this->requestFor($method, $uri, $target, $body)
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.forbidden'));

        $this->assertSame($before, User::query()->orderBy('id')->get()->makeVisible('password')->toArray());
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('endpoints')]
    public function test_administrators_are_allowed(string $method, string $uri, array $body): void
    {
        $target = User::factory()->create();
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->assertNotSame(403, $this->requestFor($method, $uri, $target, $body)->status());
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('endpoints')]
    public function test_requests_without_a_token_are_unauthenticated(string $method, string $uri, array $body): void
    {
        $target = User::factory()->create();

        $this->requestFor($method, $uri, $target, $body)->assertUnauthorized();
    }

    /**
     * @param  array<string, string>  $body
     */
    private function requestFor(string $method, string $uri, User $target, array $body): TestResponse
    {
        return $this->json($method, str_replace('{id}', (string) $target->id, $uri), $body);
    }
}
