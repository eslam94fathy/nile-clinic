<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Deactivation ends all of a member's access immediately; reactivation allows sign-in again (FR-019).
 */
class DeactivationTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    public function test_deactivation_revokes_all_tokens_and_blocks_sign_in_until_reactivated(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->create();
        $first = $this->signIn($member);
        $second = $this->signIn($member);

        $this->actAs($admin)->postJson("/api/v1/staff/{$member->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->assertSame(0, $member->tokens()->count());
        $this->usingToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->usingToken($second)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->signInAttempt($member)->assertJsonPath('errors.email.0', __('auth.failed'));

        $this->actAs($admin)->postJson("/api/v1/staff/{$member->id}/deactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);

        $this->actAs($admin)->postJson("/api/v1/staff/{$member->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->signInAttempt($member)->assertOk();
        $this->usingToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_reactivating_an_active_account_changes_nothing(): void
    {
        $admin = User::factory()->administrator()->create();
        $member = User::factory()->create();

        $this->actAs($admin)->postJson("/api/v1/staff/{$member->id}/reactivate")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);
    }

    private function actAs(User $user): static
    {
        $this->usingToken(null);
        Sanctum::actingAs($user);

        return $this;
    }

    private function signInAttempt(User $user): \Illuminate\Testing\TestResponse
    {
        return $this->usingToken(null)->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);
    }
}
