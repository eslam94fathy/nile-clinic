<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every message from the doctors and specialties endpoints exists in English and Arabic, with
 * identical JSON structure and stored values across locales (FR-004a, FR-019, SC-006).
 */
class DoctorsMessagesTest extends TestCase
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
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson('/api/v1/specialties/999999/deactivate', [], $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'specialty_not_found')]);

        $this->getJson('/api/v1/doctors/999999', $this->language($locale))
            ->assertNotFound()
            ->assertExactJson(['message' => $this->module($locale, 'messages', 'doctor_not_found')]);
    }

    #[DataProvider('locales')]
    public function test_validation_message_names_the_field_in_the_request_language(string $locale): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        Specialty::factory()->create(['name_en' => 'Cardiology']);

        $response = $this->postJson('/api/v1/specialties', ['name_en' => 'Cardiology', 'name_ar' => 'قلب'], $this->language($locale))
            ->assertUnprocessable();

        $this->assertStringContainsString(
            $this->module($locale, 'attributes', 'name_en'),
            $response->json('errors.name_en.0'),
        );
    }

    #[DataProvider('locales')]
    public function test_doctor_rules(string $locale): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $inactive = Specialty::factory()->inactive()->create();

        $this->postJson('/api/v1/doctors', [
            'user_id' => User::factory()->receptionist()->create()->id,
            'title' => 'Consultant',
            'specialty_ids' => [Specialty::factory()->create()->id],
        ], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.user_id.0', $this->module($locale, 'messages', 'not_an_active_doctor'));

        $this->postJson('/api/v1/doctors', [
            'user_id' => User::factory()->doctor()->create()->id,
            'title' => 'Consultant',
            'specialty_ids' => [$inactive->id],
        ], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.specialty_ids.0', $this->module($locale, 'messages', 'specialty_inactive'));
    }

    #[DataProvider('locales')]
    public function test_nothing_to_update(string $locale): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());
        $specialty = Specialty::factory()->create();
        $doctor = Doctor::factory()->withSpecialties()->create();
        $message = $this->module($locale, 'messages', 'nothing_to_update');

        $this->patchJson("/api/v1/specialties/{$specialty->id}", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.fields.0', $message);
        $this->patchJson("/api/v1/doctors/{$doctor->id}", [], $this->language($locale))
            ->assertUnprocessable()
            ->assertJsonPath('errors.fields.0', $message);
    }

    #[DataProvider('locales')]
    public function test_forbidden_for_receptionists(string $locale): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->postJson('/api/v1/specialties', ['name_en' => 'X', 'name_ar' => 'س'], $this->language($locale))
            ->assertForbidden()
            ->assertJsonPath('message', (require lang_path("{$locale}/auth.php"))['forbidden']);
    }

    public function test_response_structure_is_identical_in_both_languages(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $body = ['user_id' => 'x', 'title' => '', 'registration_number' => 'A-1', 'specialty_ids' => []];

        $english = $this->postJson('/api/v1/doctors', $body, $this->language('en'));
        $arabic = $this->postJson('/api/v1/doctors', $body, $this->language('ar'));

        $this->assertSame($this->shape($english), $this->shape($arabic));
        $this->assertNotSame($english->json('errors.title.0'), $arabic->json('errors.title.0'));
    }

    public function test_stored_values_are_never_translated(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());
        Doctor::factory()->create(['title' => 'Consultant'])->specialties()->attach(
            Specialty::factory()->create(['name_en' => 'Cardiology', 'name_ar' => 'أمراض القلب'])->id,
        );

        $english = $this->getJson('/api/v1/doctors', $this->language('en'))->assertOk();
        $arabic = $this->getJson('/api/v1/doctors', $this->language('ar'))->assertOk();

        $this->assertSame($english->json('data'), $arabic->json('data'));
        $this->assertSame('Cardiology', $arabic->json('data.0.specialties.0.name_en'));
        $this->assertSame('أمراض القلب', $english->json('data.0.specialties.0.name_ar'));
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
        return (require app_path("Modules/Doctors/lang/{$locale}/{$file}.php"))[$key];
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
