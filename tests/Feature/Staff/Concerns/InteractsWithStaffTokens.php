<?php

namespace Tests\Feature\Staff\Concerns;

use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;

/**
 * Helpers for tests that sign in and reuse tokens across several requests.
 */
trait InteractsWithStaffTokens
{
    /**
     * Sign in through the API and return the plain-text token.
     */
    protected function signIn(User $user, string $password = UserFactory::PASSWORD): string
    {
        return $this->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => $password,
        ])->assertOk()->json('data.token');
    }

    /**
     * Send the next request with this token (or none).
     *
     * The auth manager caches the resolved user for the whole test, so a revoked or expired
     * token would otherwise still look signed in; forgetting the guards makes every request
     * authenticate from scratch, as in production.
     */
    protected function usingToken(?string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $token === null ? $this->withoutToken() : $this->withToken($token);
    }
}
