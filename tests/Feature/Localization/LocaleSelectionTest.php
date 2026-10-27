<?php

namespace Tests\Feature\Localization;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Locale selection from Accept-Language with an English fallback (FR-017, FR-018).
 */
class LocaleSelectionTest extends TestCase
{
    private const URI = 'api/v1/_test/locale';

    protected function setUp(): void
    {
        parent::setUp();

        // Test-only route, never shipped: a validation failure yields a translated message.
        Route::middleware('api')->post(self::URI, function (Request $request) {
            $request->validate(['reference' => 'required']);
        });
    }

    /**
     * @return array<string, array{string|null, string}>
     */
    public static function headers(): array
    {
        return [
            'ar' => ['ar', 'ar'],
            'ar-EG' => ['ar-EG', 'ar'],
            'en' => ['en', 'en'],
            'en-US;q=0.8' => ['en-US;q=0.8', 'en'],
            'fr' => ['fr', 'en'],
            'malformed' => ['!!invalid', 'en'],
            'missing' => [null, 'en'],
        ];
    }

    #[DataProvider('headers')]
    public function test_selects_the_locale_from_accept_language(?string $header, string $expected): void
    {
        // Start from the other locale so a missing middleware cannot pass by default.
        App::setLocale($expected === 'en' ? 'ar' : 'en');

        // An empty value stands in for "missing": the test client sends a default header otherwise.
        $response = $this->postJson(self::URI, [], ['Accept-Language' => $header ?? '']);

        $this->assertSame($expected, App::getLocale());

        $response->assertUnprocessable()
            ->assertJsonPath('errors.reference.0', $this->requiredMessage($expected, 'reference'));
    }

    public function test_json_keys_are_identical_in_both_locales(): void
    {
        $en = $this->postJson(self::URI, [], ['Accept-Language' => 'en'])->json();
        $ar = $this->postJson(self::URI, [], ['Accept-Language' => 'ar'])->json();

        $this->assertSame(array_keys($en), array_keys($ar));
        $this->assertSame(array_keys($en['errors']), array_keys($ar['errors']));
        $this->assertNotSame($en['message'], $ar['message']);
    }

    private function requiredMessage(string $locale, string $attribute): string
    {
        $lines = require lang_path("{$locale}/validation.php");

        return str_replace(':attribute', $attribute, $lines['required']);
    }
}
