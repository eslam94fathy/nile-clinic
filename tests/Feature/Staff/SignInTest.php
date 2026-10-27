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

    /**
     * The email collation matches look-alike letters (e.g. full-width) to the same account. Such
     * emails are rejected before lookup or counting, identically whether or not an account exists,
     * so they can neither get around the per-email limit nor reveal which accounts exist.
     */
    #[DataProvider('locales')]
    public function test_non_ascii_emails_are_rejected_before_lookup_or_counting(string $locale): void
    {
        User::factory()->create(['email' => 'sara@clinic.test']);

        $existing = $this->attempt('ｓara@clinic.test', UserFactory::PASSWORD, $locale);
        $missing = $this->attempt('ｎobody@clinic.test', UserFactory::PASSWORD, $locale);

        $existing->assertUnprocessable()->assertJsonValidationErrorFor('email');
        $this->assertSame($existing->json(), $missing->json());
        $this->assertStringNotContainsString($this->authLine($locale, 'failed'), json_encode($existing->json()));

        // Rejected attempts were not counted against the real account.
        foreach (['ｓara', 'sａra', 'saｒa', 'sarａ', 'ｓａｒａ', 'ｓara'] as $variant) {
            $this->attempt("{$variant}@clinic.test", 'wrong-password-1', $locale)->assertUnprocessable();
        }

        $this->attempt('sara@clinic.test', UserFactory::PASSWORD, $locale)->assertOk();
    }

    /**
     * The collation also ignores control characters (sa\x01ra matches sara), so only printable
     * ASCII is accepted; the check must not depend on the email parser rejecting them.
     */
    public function test_control_characters_inside_emails_are_rejected(): void
    {
        User::factory()->create(['email' => 'sara@clinic.test']);

        foreach (["sa\x01ra@clinic.test", "sa\x1Fra@clinic.test", "sa\x7Fra@clinic.test", 'sa ra@clinic.test'] as $email) {
            $this->attempt($email, UserFactory::PASSWORD)
                ->assertUnprocessable()
                ->assertJsonValidationErrorFor('email');
        }
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
