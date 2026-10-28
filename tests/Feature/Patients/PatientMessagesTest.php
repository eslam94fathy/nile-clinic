<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message from the patient endpoints exists in English and Arabic, with identical JSON
 * structure across locales (FR-016, SC-007).
 */
class PatientMessagesTest extends TestCase
{
    use RefreshDatabase;

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
    public function test_not_found(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->getJson('/api/v1/patients/999999', $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'not_found')]);
    }

    #[DataProvider('locales')]
    public function test_validation_message_names_the_field_in_the_request_language(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $response = $this->postJson('/api/v1/patients', [
            'full_name' => 'Synthetic Patient',
            'date_of_birth' => '1985-02-03',
            'sex' => 'male',
            'phone' => '12',
        ], $this->language($locale))->assertUnprocessable();

        $this->assertStringContainsString(
            $this->module($locale, 'attributes', 'phone'),
            $response->json('errors.phone.0'),
        );
    }

    #[DataProvider('locales')]
    public function test_nothing_to_update(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.fields.0', $this->module($locale, 'messages', 'nothing_to_update'));
    }

    #[DataProvider('locales')]
    public function test_forbidden_for_doctors(string $locale): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/v1/patients', $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', (require lang_path("{$locale}/auth.php"))['forbidden']);
    }

    public function test_response_structure_is_identical_in_both_languages(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $body = ['full_name' => '', 'phone' => '12', 'national_id' => '1'];

        $english = $this->postJson('/api/v1/patients', $body, $this->language('en'));
        $arabic = $this->postJson('/api/v1/patients', $body, $this->language('ar'));

        $this->assertSame($this->shape($english), $this->shape($arabic));
        $this->assertNotSame($english->json('errors.phone.0'), $arabic->json('errors.phone.0'));
    }

    /**
     * @return array<string, string>
     */
    private function language(string $locale): array
    {
        return ['Accept-Language' => $locale];
    }

    private function module(string $locale, string $file, string $key): string
    {
        return (require app_path("Modules/Patients/lang/{$locale}/{$file}.php"))[$key];
    }

    /**
     * The status and the error keys, without the translated texts.
     *
     * @return array{int, list<string>}
     */
    private function shape(TestResponse $response): array
    {
        return [$response->status(), array_keys($response->json('errors'))];
    }
}
