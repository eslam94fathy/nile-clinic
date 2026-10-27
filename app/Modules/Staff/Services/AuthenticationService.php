<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\AccessTokenRepository;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sign-in, sign-out, and token validity for staff accounts (research R2, R7).
 */
class AuthenticationService
{
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Checked when the email is unknown, so failures take the same time either way (SC-004).
     */
    private static ?string $dummyHash = null;

    public function __construct(
        private readonly StaffMemberRepository $staffMembers,
        private readonly AccessTokenRepository $tokens,
    ) {}

    /**
     * @return array{token: string, user: User}
     *
     * @throws ValidationException
     */
    public function signIn(string $email, string $password, string $ip): array
    {
        $key = $email.'|'.$ip;

        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $user = $this->staffMembers->findByEmail($email);
        $passwordMatches = Hash::check($password, $user?->password ?? $this->dummyHash());

        if (! $user || ! $passwordMatches || ! $user->is_active) {
            RateLimiter::hit($key, self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($key);
        $this->tokens->deleteIdleFor($user, $this->idleCutoff());

        return ['token' => $this->tokens->issueFor($user), 'user' => $user];
    }

    public function signOut(User $user): void
    {
        $this->tokens->deleteCurrent($user);
    }

    /**
     * Sanctum's token callback: valid, belongs to an active account, and used within the idle timeout.
     */
    public function isTokenUsable(PersonalAccessToken $token, bool $isValid): bool
    {
        $user = $token->tokenable;
        $lastActivity = $token->last_used_at ?? $token->created_at;

        return $isValid
            && $user instanceof User
            && $user->is_active
            && $lastActivity->greaterThan($this->idleCutoff());
    }

    private function idleCutoff(): CarbonInterface
    {
        return now()->subMinutes(config('sanctum.idle_timeout_minutes'));
    }

    private function dummyHash(): string
    {
        return self::$dummyHash ??= Hash::make('timing-guard-not-a-password');
    }
}
