<?php

namespace App\Modules\Staff\Services;

use App\Modules\Staff\Models\User;
use App\Modules\Staff\Repositories\Contracts\AccessTokenRepository;
use App\Modules\Staff\Repositories\Contracts\StaffMemberRepository;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
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
        // The email is lower-cased ASCII (SignInRequest), so this key and the case-insensitive
        // lookup always agree, and it is built the same way whether or not an account exists.
        $emailKey = $this->accountAttemptsKey($email);
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
     * Change the signed-in member's password and end their other sessions; the token used for
     * this request stays valid (FR-023).
     *
     * Wrong current passwords count against the same per-account limit as sign-in (FR-007), so a
     * stolen or unattended token cannot be used to guess the password without limit.
     *
     * @throws ValidationException
     */
    public function changeOwnPassword(User $user, string $currentPassword, string $password): void
    {
        $key = $this->accountAttemptsKey($user->email);

        if (RateLimiter::hit($key, self::DECAY_SECONDS) > self::MAX_ATTEMPTS_PER_EMAIL) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        if (! Hash::check($currentPassword, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => __('validation.current_password'),
            ]);
        }

        RateLimiter::clear($key);

        DB::transaction(function () use ($user, $password) {
            $this->staffMembers->update($user, ['password' => $password]);

            $current = $user->currentAccessToken();

            $current instanceof PersonalAccessToken
                ? $this->tokens->deleteAllExcept($user, $current)
                : $this->tokens->deleteAllFor($user);
        });
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

    /**
     * The per-account attempt counter shared by sign-in and own password change.
     */
    private function accountAttemptsKey(string $email): string
    {
        return 'sign-in:email:'.$email;
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
