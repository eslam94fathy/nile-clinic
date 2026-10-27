<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * POST /api/v1/auth/sign-in (US1, FR-005–FR-007, SC-004).
 */
class SignInTest extends TestCase
{
    use RefreshDatabase;

    private const URI = '/api/v1/auth/sign-in';

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

    public function test_active_staff_member_signs_in(): void
    {
        $user = User::factory()->doctor()->create();

        $response = $this->postJson(self::URI, [
            'email' => $user->email,
            'password' => UserFactory::PASSWORD,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.id', $user->id)
            ->assertJsonPath('data.user.email', $user->email)
            ->assertJsonPath('data.user.role', 'doctor')
            ->assertJsonMissingPath('data.user.password');

        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_email_ignores_case_and_surrounding_spaces(): void
    {
        User::factory()->create(['email' => 'sara@clinic.test']);

        $this->postJson(self::URI, [
            'email' => ' Sara@Clinic.test ',
            'password' => UserFactory::PASSWORD,
        ])->assertOk()->assertJsonPath('data.user.email', 'sara@clinic.test');
    }

    #[DataProvider('locales')]
    public function test_unknown_wrong_and_deactivated_get_one_generic_failure(string $locale): void
    {
        User::factory()->create(['email' => 'known@clinic.test']);
        User::factory()->inactive()->create(['email' => 'inactive@clinic.test']);

        $unknown = $this->attempt('nobody@clinic.test', UserFactory::PASSWORD, $locale);
        $wrong = $this->attempt('known@clinic.test', 'wrong-password-1', $locale);
        $inactive = $this->attempt('inactive@clinic.test', UserFactory::PASSWORD, $locale);

        foreach ([$unknown, $wrong, $inactive] as $response) {
            $response->assertUnprocessable()
                ->assertJsonPath('errors.email.0', $this->authLine($locale, 'failed'));
        }

        $this->assertSame($unknown->json(), $wrong->json());
        $this->assertSame($unknown->json(), $inactive->json());
    }

    #[DataProvider('locales')]
    public function test_sixth_failed_attempt_within_a_minute_is_throttled(string $locale): void
    {
        User::factory()->create(['email' => 'known@clinic.test']);

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('known@clinic.test', 'wrong-password-1', $locale)
                ->assertUnprocessable()
                ->assertJsonPath('errors.email.0', $this->authLine($locale, 'failed'));
        }

        $throttled = $this->attempt('known@clinic.test', UserFactory::PASSWORD, $locale);

        $throttled->assertUnprocessable();
        $this->assertMatchesRegularExpression(
            $this->linePattern($this->authLine($locale, 'throttle')),
            $throttled->json('errors.email.0'),
        );
    }

    public function test_email_limit_applies_across_client_addresses(): void
    {
        User::factory()->create(['email' => 'known@clinic.test']);

        for ($i = 1; $i <= 5; $i++) {
            $this->fromAddress("10.0.0.{$i}")
                ->attempt('known@clinic.test', 'wrong-password-1')
                ->assertJsonPath('errors.email.0', $this->authLine('en', 'failed'));
        }

        $throttled = $this->fromAddress('10.0.0.99')->attempt('known@clinic.test', UserFactory::PASSWORD);

        $throttled->assertUnprocessable();
        $this->assertMatchesRegularExpression(
            $this->linePattern($this->authLine('en', 'throttle')),
            $throttled->json('errors.email.0'),
        );
    }

    public function test_email_limit_counts_variants_that_reach_the_same_account(): void
    {
        User::factory()->create(['email' => 'sara@clinic.test']);

        // Full-width letters: the database collation matches these to the same account, so they
        // must share one counter. (Accents are already folded by the rate limiter's key cleaning.)
        foreach (['ｓara', 'sａra', 'saｒa', 'sarａ', 'ｓａｒａ'] as $i => $variant) {
            $this->fromAddress('10.0.1.'.($i + 1))
                ->attempt("{$variant}@clinic.test", 'wrong-password-1')
                ->assertJsonPath('errors.email.0', $this->authLine('en', 'failed'));
        }

        $throttled = $this->fromAddress('10.0.1.99')->attempt('sara@clinic.test', UserFactory::PASSWORD);

        $throttled->assertUnprocessable();
        $this->assertMatchesRegularExpression(
            $this->linePattern($this->authLine('en', 'throttle')),
            $throttled->json('errors.email.0'),
        );
    }

    public function test_client_address_limit_applies_across_emails(): void
    {
        $user = User::factory()->create(['email' => 'known@clinic.test']);

        for ($i = 1; $i <= 20; $i++) {
            $this->fromAddress('10.0.0.1')
                ->attempt("nobody{$i}@clinic.test", 'wrong-password-1')
                ->assertJsonPath('errors.email.0', $this->authLine('en', 'failed'));
        }

        $throttled = $this->fromAddress('10.0.0.1')->attempt($user->email, UserFactory::PASSWORD);

        $throttled->assertUnprocessable();
        $this->assertMatchesRegularExpression(
            $this->linePattern($this->authLine('en', 'throttle')),
            $throttled->json('errors.email.0'),
        );

        $this->fromAddress('10.0.0.2')->attempt($user->email, UserFactory::PASSWORD)->assertOk();
    }

    public function test_successful_sign_in_clears_the_failure_counter(): void
    {
        User::factory()->create(['email' => 'known@clinic.test']);

        for ($i = 1; $i <= 4; $i++) {
            $this->attempt('known@clinic.test', 'wrong-password-1')->assertUnprocessable();
        }

        $this->attempt('known@clinic.test', UserFactory::PASSWORD)->assertOk();

        for ($i = 1; $i <= 5; $i++) {
            $this->attempt('known@clinic.test', 'wrong-password-1')
                ->assertJsonPath('errors.email.0', $this->authLine('en', 'failed'));
        }
    }

    public function test_email_and_password_are_required(): void
    {
        $this->postJson(self::URI, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['email', 'password']);
    }

    private function attempt(string $email, string $password, string $locale = 'en'): TestResponse
    {
        return $this->postJson(self::URI, ['email' => $email, 'password' => $password], [
            'Accept-Language' => $locale,
        ]);
    }

    private function fromAddress(string $ip): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip]);
    }

    private function authLine(string $locale, string $key): string
    {
        return (require lang_path("{$locale}/auth.php"))[$key];
    }

    /**
     * A regex for a translation line whose :placeholders may hold any value.
     */
    private function linePattern(string $line): string
    {
        return '/^'.preg_replace('/\\\\:[a-z_]+/', '.+', preg_quote($line, '/')).'$/u';
    }
}
