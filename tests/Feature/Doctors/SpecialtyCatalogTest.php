<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\SpecialtyService;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators maintain the specialty catalog; every active staff member can read it (US1,
 * FR-001–FR-004a).
 */
class SpecialtyCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const SPECIALTY_KEYS = ['id', 'name_en', 'name_ar', 'is_active'];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    public function test_administrator_adds_a_specialty_with_cleaned_names(): void
    {
        $response = $this->postJson('/api/v1/specialties', ['name_en' => '  Cardiology ', 'name_ar' => 'أمراض   القلب'])
            ->assertCreated()
            ->assertJsonPath('data.name_en', 'Cardiology')
            ->assertJsonPath('data.name_ar', 'أمراض القلب')
            ->assertJsonPath('data.is_active', true);

        $this->assertEqualsCanonicalizing(self::SPECIALTY_KEYS, array_keys($response->json('data')));
        $this->assertDatabaseHas('specialties', ['name_en' => 'Cardiology', 'is_active' => true]);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function duplicateNames(): array
    {
        return [
            'English name, other case' => [['name_en' => 'cardiology', 'name_ar' => 'قلب'], 'name_en'],
            'English name, extra spaces' => [['name_en' => ' Cardiology  ', 'name_ar' => 'قلب'], 'name_en'],
            'Arabic name' => [['name_en' => 'Heart', 'name_ar' => 'أمراض القلب'], 'name_ar'],
        ];
    }

    /**
     * @param  array<string, string>  $payload
     */
    #[DataProvider('duplicateNames')]
    public function test_names_must_be_unique_per_language(array $payload, string $field): void
    {
        Specialty::factory()->create(['name_en' => 'Cardiology', 'name_ar' => 'أمراض القلب']);

        $this->postJson('/api/v1/specialties', $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('specialties', 1);
    }

    public function test_names_of_deactivated_specialties_stay_taken(): void
    {
        Specialty::factory()->inactive()->create(['name_en' => 'Cardiology']);

        $this->postJson('/api/v1/specialties', ['name_en' => 'Cardiology', 'name_ar' => 'قلب'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name_en']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidNames(): array
    {
        return [
            'English name missing' => [['name_ar' => 'قلب'], 'name_en'],
            'Arabic name missing' => [['name_en' => 'Heart'], 'name_ar'],
            'English name blank' => [['name_en' => '   ', 'name_ar' => 'قلب'], 'name_en'],
            'English name too long' => [['name_en' => str_repeat('a', 101), 'name_ar' => 'قلب'], 'name_en'],
            'Arabic name too long' => [['name_en' => 'Heart', 'name_ar' => str_repeat('ق', 101)], 'name_ar'],
            'English name not a string' => [['name_en' => ['x'], 'name_ar' => 'قلب'], 'name_en'],
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('invalidNames')]
    public function test_invalid_names_are_rejected(array $payload, string $field): void
    {
        $this->postJson('/api/v1/specialties', $payload)->assertUnprocessable()->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('specialties', 0);
    }

    public function test_names_of_100_characters_are_accepted(): void
    {
        $this->postJson('/api/v1/specialties', ['name_en' => str_repeat('a', 100), 'name_ar' => str_repeat('ق', 100)])
            ->assertCreated();
    }

    public function test_administrator_renames_one_name(): void
    {
        $specialty = Specialty::factory()->create(['name_en' => 'Cardiology', 'name_ar' => 'قلب']);

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'Cardiac Medicine'])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'Cardiac Medicine')
            ->assertJsonPath('data.name_ar', 'قلب');
    }

    public function test_renaming_to_its_own_name_is_allowed(): void
    {
        $specialty = Specialty::factory()->create(['name_en' => 'Cardiology']);

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'cardiology'])
            ->assertOk()
            ->assertJsonPath('data.name_en', 'cardiology');
    }

    public function test_renaming_to_another_specialtys_name_is_rejected(): void
    {
        Specialty::factory()->create(['name_ar' => 'قلب']);
        $specialty = Specialty::factory()->create();

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_ar' => 'قلب'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name_ar']);
    }

    public function test_an_empty_rename_is_rejected(): void
    {
        $specialty = Specialty::factory()->create();

        $this->patchJson("/api/v1/specialties/{$specialty->id}", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields']);
    }

    public function test_renaming_an_unknown_specialty_is_not_found(): void
    {
        $this->patchJson('/api/v1/specialties/999', ['name_en' => 'Heart'])->assertNotFound();
    }

    public function test_deactivate_and_reactivate_are_idempotent(): void
    {
        $specialty = Specialty::factory()->create();
        $url = "/api/v1/specialties/{$specialty->id}";

        $this->postJson("{$url}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("{$url}/deactivate")->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson("{$url}/reactivate")->assertOk()->assertJsonPath('data.is_active', true);
        $this->postJson("{$url}/reactivate")->assertOk()->assertJsonPath('data.is_active', true);
    }

    public function test_status_changes_on_an_unknown_specialty_are_not_found(): void
    {
        $this->postJson('/api/v1/specialties/999/deactivate')->assertNotFound();
        $this->postJson('/api/v1/specialties/999/reactivate')->assertNotFound();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function readers(): array
    {
        return ['receptionist' => ['receptionist'], 'doctor' => ['doctor'], 'administrator' => ['administrator']];
    }

    #[DataProvider('readers')]
    public function test_every_role_lists_the_catalog_ordered_by_english_name(string $role): void
    {
        Specialty::factory()->create(['name_en' => 'Pediatrics']);
        Specialty::factory()->inactive()->create(['name_en' => 'Cardiology']);
        Sanctum::actingAs(User::factory()->{$role}()->create());

        $response = $this->getJson('/api/v1/specialties')->assertOk();

        $this->assertSame(['Cardiology', 'Pediatrics'], array_column($response->json('data'), 'name_en'));
        foreach ($response->json('data') as $item) {
            $this->assertEqualsCanonicalizing(self::SPECIALTY_KEYS, array_keys($item));
        }
    }

    public function test_the_list_filters_by_active_status(): void
    {
        Specialty::factory()->create(['name_en' => 'Pediatrics']);
        Specialty::factory()->inactive()->create(['name_en' => 'Cardiology']);

        $this->getJson('/api/v1/specialties?is_active=1')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Pediatrics');
        $this->getJson('/api/v1/specialties?is_active=0')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name_en', 'Cardiology');
        $this->getJson('/api/v1/specialties?is_active=maybe')->assertUnprocessable()
            ->assertJsonValidationErrors(['is_active']);
    }

    public function test_a_simultaneous_duplicate_is_reported_as_a_validation_error(): void
    {
        Specialty::factory()->create(['name_en' => 'Cardiology']);

        try {
            app(SpecialtyService::class)->create(['name_en' => 'Cardiology', 'name_ar' => 'قلب']);
            $this->fail('A duplicate name was stored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('name_en', $e->errors());
        }

        $this->assertDatabaseCount('specialties', 1);
    }
}
