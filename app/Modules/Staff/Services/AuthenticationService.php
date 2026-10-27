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
    /**
     * Failed attempts allowed per email (account), from any client, per window (FR-007).
     */
    private const MAX_ATTEMPTS_PER_EMAIL = 5;

    /**
     * Attempts allowed per client address, for any email, per window. Looser than the email limit
     * because clinic staff usually share one public address (FR-007).
     */
    private const MAX_ATTEMPTS_PER_IP = 20;

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
        $emailKey = 'sign-in:email:'.$email;
        $ipKey = 'sign-in:ip:'.$ip;

        // Count first, then check, so simultaneous requests cannot slip past the limits.
        $emailAttempts = RateLimiter::hit($emailKey, self::DECAY_SECONDS);
        $ipAttempts = RateLimiter::hit($ipKey, self::DECAY_SECONDS);

        if ($emailAttempts > self::MAX_ATTEMPTS_PER_EMAIL || $ipAttempts > self::MAX_ATTEMPTS_PER_IP) {
            throw ValidationException::withMessages([
                'email' => __('auth.throttle', [
                    'seconds' => max(RateLimiter::availableIn($emailKey), RateLimiter::availableIn($ipKey)),
                ]),
            ]);
        }

        $user = $this->staffMembers->findByEmail($email);
        $passwordMatches = Hash::check($password, $user?->password ?? $this->dummyHash());

        if (! $user || ! $passwordMatches || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        // Only the account's counter resets; the address counter keeps running, so signing in to
        // one account cannot reset an address that is guessing others.
        RateLimiter::clear($emailKey);
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
