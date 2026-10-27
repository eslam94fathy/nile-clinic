<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Tokens expire after 8 hours without a request; each request restarts the period (FR-009),
 * and tokens of deactivated accounts stop working (FR-010).
 */
class TokenExpiryTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    public function test_token_still_works_just_before_eight_idle_hours(): void
    {
        $token = $this->signIn(User::factory()->create());

        $this->travel(7 * 60 + 59)->minutes();

        $this->usingToken($token)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_token_expires_after_eight_idle_hours(): void
    {
        $token = $this->signIn(User::factory()->create());

        $this->travel(8 * 60 + 1)->minutes();

        $this->usingToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_each_request_restarts_the_idle_period(): void
    {
        $token = $this->signIn(User::factory()->create());

        foreach ([5, 10, 15] as $hours) {
            $this->travel(5)->hours();

            $this->usingToken($token)->getJson('/api/v1/auth/me')
                ->assertOk("Refused {$hours} hours after sign-in");
        }
    }

    public function test_token_of_a_deactivated_account_is_refused(): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        $user->forceFill(['is_active' => false])->save();

        $this->usingToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
    }
}
