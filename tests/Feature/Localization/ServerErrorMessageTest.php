<?php

namespace Tests\Feature\Localization;

use App\Modules\Staff\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Tests\TestCase;

/**
 * The general error follows Accept-Language outside debug mode (Constitution VII, research R13), and
 * no other response changes.
 */
class ServerErrorMessageTest extends TestCase
{
    use RefreshDatabase;

    private const PLANTED = 'PLANTED-EXCEPTION-TEXT';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('api')->prefix('api/v1')->group(function () {
            Route::get('/test-throws/runtime', fn () => throw new RuntimeException(self::PLANTED));
            Route::get('/test-throws/validation', fn () => throw ValidationException::withMessages(['field' => 'Synthetic message']));
            Route::get('/test-throws/unauthenticated', fn () => throw new AuthenticationException);
            Route::get('/test-throws/forbidden', fn () => throw new AccessDeniedHttpException);
        });
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function locales(): array
    {
        return [
            'en' => ['en', 'Something went wrong. Please try again.'],
            'ar' => ['ar', 'حدث خطأ ما. يرجى المحاولة مرة أخرى.'],
        ];
    }

    #[DataProvider('locales')]
    public function test_the_general_error_is_translated_and_hides_the_exception(string $locale, string $message): void
    {
        config(['app.debug' => false]);

        $response = $this->getJson('/api/v1/test-throws/runtime', ['Accept-Language' => $locale]);

        $response->assertStatus(500)->assertExactJson(['message' => $message]);
        $this->assertStringNotContainsString(self::PLANTED, $response->getContent());
    }

    public function test_debug_output_is_not_replaced(): void
    {
        config(['app.debug' => true]);

        $this->getJson('/api/v1/test-throws/runtime')
            ->assertStatus(500)
            ->assertJsonPath('message', self::PLANTED);
    }

    public function test_other_statuses_are_unchanged(): void
    {
        config(['app.debug' => false]);

        $this->getJson('/api/v1/test-throws/validation')
            ->assertStatus(422)
            ->assertJsonPath('errors.field.0', 'Synthetic message');

        $this->getJson('/api/v1/test-throws/unauthenticated')
            ->assertStatus(401)
            ->assertExactJson(['message' => __('auth.unauthenticated')]);

        $this->getJson('/api/v1/test-throws/forbidden')
            ->assertStatus(403)
            ->assertExactJson(['message' => __('auth.forbidden')]);

        $this->getJson('/api/v1/no-such-route')->assertStatus(404);
    }

    public function test_a_real_module_refusal_is_unchanged(): void
    {
        config(['app.debug' => false]);

        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->getJson('/api/v1/patients/999999')
            ->assertStatus(404)
            ->assertJsonMissing(['message' => __('auth.server_error')]);
    }
}
