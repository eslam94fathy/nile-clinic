<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * GET /api/v1/auth/me (US1, FR-011).
 */
class OwnProfileTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    /**
     * @return array<string, array{Role}>
     */
    public static function roles(): array
    {
        return [
            'administrator' => [Role::Administrator],
            'receptionist' => [Role::Receptionist],
            'doctor' => [Role::Doctor],
        ];
    }

    #[DataProvider('roles')]
    public function test_profile_shows_exactly_the_public_fields(Role $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $response = $this->usingToken($this->signIn($user))->getJson('/api/v1/auth/me');

        $response->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonPath('data.name', $user->name)
            ->assertJsonPath('data.email', $user->email)
            ->assertJsonPath('data.role', $role->value)
            ->assertJsonPath('data.is_active', true);

        $keys = array_keys($response->json('data'));
        sort($keys);

        $this->assertSame(['created_at', 'email', 'id', 'is_active', 'name', 'role', 'updated_at'], $keys);
    }

    public function test_profile_requires_a_token(): void
    {
        $this->usingToken(null)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
