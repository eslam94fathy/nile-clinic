<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * POST /api/v1/auth/sign-out (US1, FR-008).
 */
class SignOutTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    public function test_sign_out_ends_only_the_token_used(): void
    {
        $user = User::factory()->create();
        $first = $this->signIn($user);
        $second = $this->signIn($user);

        $this->usingToken($first)->postJson('/api/v1/auth/sign-out')->assertNoContent();

        $this->usingToken($first)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->usingToken($second)->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_sign_out_requires_a_token(): void
    {
        $this->usingToken(null)->postJson('/api/v1/auth/sign-out')->assertUnauthorized();
    }
}
