<?php

namespace Tests\Feature\Api;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unauthenticated (401) and forbidden (403) API responses are translated and distinguishable
 * (FR-010, FR-013, FR-027).
 */
class AuthErrorsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Test-only routes, never shipped.
        Gate::define('_test-deny', fn () => false);

        Route::middleware(['api', 'auth:sanctum'])
            ->get('api/v1/_test/protected', fn () => 'ok');

        Route::middleware('api')
            ->get('api/v1/_test/forbidden', fn () => Gate::authorize('_test-deny'));
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
    public function test_missing_token_is_unauthenticated(string $locale): void
    {
        $this->getJson('api/v1/_test/protected', ['Accept-Language' => $locale])
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->message($locale, 'unauthenticated'));
    }

    #[DataProvider('locales')]
    public function test_malformed_token_is_unauthenticated(string $locale): void
    {
        $this->getJson('api/v1/_test/protected', [
            'Accept-Language' => $locale,
            'Authorization' => 'Bearer abc',
        ])
            ->assertUnauthorized()
            ->assertJsonPath('message', $this->message($locale, 'unauthenticated'));
    }

    /**
     * Clients that do not send Accept: application/json (e.g. plain curl) must also get a JSON
     * 401; the framework's default would try to redirect to a "login" route this API does not have.
     */
    #[DataProvider('locales')]
    public function test_unauthenticated_is_json_even_without_a_json_accept_header(string $locale): void
    {
        $this->get('api/v1/_test/protected', ['Accept' => 'text/html', 'Accept-Language' => $locale])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', $this->message($locale, 'unauthenticated'));

        $this->get('api/v1/_test/protected', ['Accept' => '*/*', 'Authorization' => 'Bearer 1|revoked'])
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json');
    }

    #[DataProvider('locales')]
    public function test_denied_ability_is_forbidden(string $locale): void
    {
        $this->getJson('api/v1/_test/forbidden', ['Accept-Language' => $locale])
            ->assertForbidden()
            ->assertJsonPath('message', $this->message($locale, 'forbidden'));
    }

    public function test_json_keys_are_identical_in_both_locales(): void
    {
        foreach (['api/v1/_test/protected', 'api/v1/_test/forbidden'] as $uri) {
            $en = $this->getJson($uri, ['Accept-Language' => 'en'])->json();
            $ar = $this->getJson($uri, ['Accept-Language' => 'ar'])->json();

            $this->assertSame(array_keys($en), array_keys($ar), $uri);
            $this->assertNotSame($en['message'], $ar['message'], $uri);
        }
    }

    private function message(string $locale, string $key): string
    {
        $lines = require lang_path("{$locale}/auth.php");

        return $lines[$key];
    }
}
