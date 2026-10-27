<?php

namespace Tests\Feature\Staff;

use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Staff\Concerns\InteractsWithStaffTokens;
use Tests\TestCase;

/**
 * Every refusal message added by feature 002 exists in English and Arabic, with identical JSON
 * structure across locales (SC-007, FR-027).
 */
class StaffMessagesTest extends TestCase
{
    use InteractsWithStaffTokens, RefreshDatabase;

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
    public function test_unauthenticated(string $locale): void
    {
        $this->usingToken(null)->getJson('/api/v1/auth/me', $this->language($locale))
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->framework($locale, 'auth', 'unauthenticated'));
    }

    #[DataProvider('locales')]
    public function test_forbidden(string $locale): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/v1/staff', $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', $this->framework($locale, 'auth', 'forbidden'));
    }

    #[DataProvider('locales')]
    public function test_not_found(string $locale): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->getJson('/api/v1/staff/999999', $this->language($locale))
            ->assertNotFound()
            ->assertJsonPath('message', $this->module($locale, 'not_found'));
    }

    #[DataProvider('locales')]
    public function test_failed_sign_in(string $locale): void
    {
        $this->signInWith('nobody@clinic.test', 'wrong-password-1', $locale)
            ->assertUnprocessable()
            ->assertJsonPath('errors.email.0', $this->framework($locale, 'auth', 'failed'));
    }

    #[DataProvider('locales')]
    public function test_too_many_sign_in_attempts(string $locale): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->signInWith('nobody@clinic.test', 'wrong-password-1', $locale);
        }

        $line = $this->framework($locale, 'auth', 'throttle');
        $pattern = '/^'.preg_replace('/\\\\:[a-z_]+/', '\d+', preg_quote($line, '/')).'$/u';

        $this->assertMatchesRegularExpression(
            $pattern,
            $this->signInWith('nobody@clinic.test', 'wrong-password-1', $locale)->json('errors.email.0'),
        );
    }

    #[DataProvider('locales')]
    public function test_last_administrator(string $locale): void
    {
        $admin = User::factory()->administrator()->create();
        Sanctum::actingAs($admin);

        $this->postJson("/api/v1/staff/{$admin->id}/deactivate", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('message', $this->module($locale, 'last_administrator'));
    }

    public function test_refusals_have_the_same_structure_in_both_locales(): void
    {
        $admin = User::factory()->administrator()->create();
        $doctor = User::factory()->doctor()->create();

        $requests = [
            'unauthenticated' => fn (string $l) => $this->usingToken(null)->getJson('/api/v1/auth/me', $this->language($l)),
            'forbidden' => function (string $l) use ($doctor) {
                Sanctum::actingAs($doctor);

                return $this->getJson('/api/v1/staff', $this->language($l));
            },
            'not found' => function (string $l) use ($admin) {
                Sanctum::actingAs($admin);

                return $this->getJson('/api/v1/staff/999999', $this->language($l));
            },
            'failed sign-in' => fn (string $l) => $this->usingToken(null)->signInWith('nobody@clinic.test', 'wrong-password-1', $l),
            'last administrator' => function (string $l) use ($admin) {
                Sanctum::actingAs($admin);

                return $this->postJson("/api/v1/staff/{$admin->id}/deactivate", [], $this->language($l));
            },
        ];

        foreach ($requests as $name => $request) {
            $en = $request('en');
            $ar = $request('ar');

            $this->assertSame($en->status(), $ar->status(), $name);
            $this->assertSame($this->shape($en->json()), $this->shape($ar->json()), $name);
            $this->assertNotSame($en->json('message'), $ar->json('message'), $name);
        }
    }

    private function signInWith(string $email, string $password, string $locale): TestResponse
    {
        return $this->postJson('/api/v1/auth/sign-in', [
            'email' => $email,
            'password' => $password,
        ], $this->language($locale));
    }

    /**
     * @return array{Accept-Language: string}
     */
    private function language(string $locale): array
    {
        return ['Accept-Language' => $locale];
    }

    private function framework(string $locale, string $file, string $key): string
    {
        return (require lang_path("{$locale}/{$file}.php"))[$key];
    }

    private function module(string $locale, string $key): string
    {
        return (require app_path("Modules/Staff/lang/{$locale}/messages.php"))[$key];
    }

    /**
     * The keys of a JSON body, recursively, without their values.
     *
     * @param  array<string, mixed>  $json
     * @return array<string, mixed>
     */
    private function shape(array $json): array
    {
        return array_map(fn ($value) => is_array($value) ? $this->shape($value) : null, $json);
    }
}
