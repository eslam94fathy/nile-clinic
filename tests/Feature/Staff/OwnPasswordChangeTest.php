<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * PUT /api/v1/auth/password: a staff member changes their own password (US5, FR-023).
 */
class OwnPasswordChangeTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

    private const URI = '/api/v1/auth/password';

    private const NEW_PASSWORD = 'my-new-password-1';

    public function test_password_changes_other_tokens_end_and_this_one_stays(): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $other = $this->signIn($user);

        $this->usingToken($current)->putJson(self::URI, [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertNoContent();

        $this->usingToken($current)->getJson('/api/v1/auth/me')->assertOk();
        $this->usingToken($other)->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->usingToken(null)->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertUnprocessable();

        $this->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => self::NEW_PASSWORD,
        ])->assertOk();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function locales(): array
    {
        return [
            'en' => ['en'],
            'ar' => ['ar'],
        ];
    }

    #[DataProvider('locales')]
    public function test_wrong_current_password_is_rejected_and_nothing_changes(string $locale): void
    {
        $user = User::factory()->create();
        $current = $this->signIn($user);
        $other = $this->signIn($user);

        $this->usingToken($current)->putJson(self::URI, [
            'current_password' => 'not-my-password-1',
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], ['Accept-Language' => $locale])
            ->assertUnprocessable()
            ->assertJsonPath('errors.current_password.0', (require lang_path("{$locale}/validation.php"))['current_password']);

        $this->usingToken($other)->getJson('/api/v1/auth/me')->assertOk();
        $this->usingToken(null)->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertOk();
    }

    /**
     * Wrong current passwords count against the same per-account limit as sign-in (FR-007), so a
     * stolen or unattended token cannot be used to guess the password without limit.
     */
    #[DataProvider('locales')]
    public function test_wrong_current_passwords_are_rate_limited_together_with_sign_in(string $locale): void
    {
        $user = User::factory()->create();
        $token = $this->signIn($user);

        for ($i = 1; $i <= 5; $i++) {
            $this->usingToken($token)->putJson(self::URI, [
                'current_password' => "wrong-guess-{$i}-pass",
                'password' => self::NEW_PASSWORD,
                'password_confirmation' => self::NEW_PASSWORD,
            ], ['Accept-Language' => $locale])->assertJsonPath(
                'errors.current_password.0',
                (require lang_path("{$locale}/validation.php"))['current_password'],
            );
        }

        $throttled = $this->usingToken($token)->putJson(self::URI, [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ], ['Accept-Language' => $locale]);

        $throttled->assertUnprocessable();
        $this->assertMatchesRegularExpression(
            $this->linePattern((require lang_path("{$locale}/auth.php"))['throttle']),
            $throttled->json('errors.current_password.0'),
        );

        $this->usingToken(null)->postJson('/api/v1/auth/sign-in', [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ])->assertUnprocessable();
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidNewPasswords(): array
    {
        return [
            '11 characters' => [str_repeat('p', 11), str_repeat('p', 11)],
            'unconfirmed' => [self::NEW_PASSWORD, 'different-pass-1'],
        ];
    }

    #[DataProvider('invalidNewPasswords')]
    public function test_new_password_rules_apply(string $password, string $confirmation): void
    {
        $token = $this->signIn(User::factory()->create());

        $this->usingToken($token)->putJson(self::URI, [
            'current_password' => UserFactory::PASSWORD,
            'password' => $password,
            'password_confirmation' => $confirmation,
        ])->assertJsonValidationErrorFor('password');
    }

    public function test_current_password_is_required(): void
    {
        $token = $this->signIn(User::factory()->create());

        $this->usingToken($token)->putJson(self::URI, [
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertJsonValidationErrorFor('current_password');
    }

    public function test_requires_a_token(): void
    {
        $this->usingToken(null)->putJson(self::URI, [
            'current_password' => UserFactory::PASSWORD,
            'password' => self::NEW_PASSWORD,
            'password_confirmation' => self::NEW_PASSWORD,
        ])->assertUnauthorized();
    }

    /**
     * A regex for a translation line whose :placeholders may hold any value.
     */
    private function linePattern(string $line): string
    {
        return '/^'.preg_replace('/\\\\:[a-z_]+/', '.+', preg_quote($line, '/')).'$/u';
    }
}
