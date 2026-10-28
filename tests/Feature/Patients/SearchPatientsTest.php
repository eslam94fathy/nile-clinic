<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Staff list and search patients; lists show only the summary fields (US2, FR-011–FR-013).
 */
class SearchPatientsTest extends TestCase
{
    use RefreshDatabase;

    private const SUMMARY_KEYS = ['id', 'full_name', 'sex', 'date_of_birth', 'phone'];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_without_filters_all_patients_are_listed_by_name(): void
    {
        foreach (['Zeina Omar', 'adam Fathy', 'Bassem Ali'] as $name) {
            Patient::factory()->create(['full_name' => $name]);
        }

        $this->getJson('/api/v1/patients')
            ->assertOk()
            ->assertJsonPath('data.*.full_name', ['adam Fathy', 'Bassem Ali', 'Zeina Omar'])
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 3);
    }

    public function test_name_search_matches_part_of_the_name_ignoring_case(): void
    {
        $match = Patient::factory()->create(['full_name' => 'Karim Mostafa Hassan']);
        Patient::factory()->create(['full_name' => 'Nour Adel']);

        $this->getJson('/api/v1/patients?name=MOSTAFA')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function arabicVariants(): array
    {
        return [
            'hamza above alef' => ['أحمد علي', 'احمد'],
            'plain alef finds hamza above' => ['احمد علي', 'أحمد'],
            'hamza below alef' => ['إيمان سعيد', 'ايمان'],
            'plain alef finds hamza below' => ['ايمان سعيد', 'إيمان'],
            'madda alef' => ['آمال حسن', 'امال'],
            'taa marbuta found by haa' => ['فاطمة يوسف', 'فاطمه'],
            'haa found by taa marbuta' => ['فاطمه يوسف', 'فاطمة'],
            'alef maqsura found by yaa' => ['مصطفى كامل', 'مصطفي'],
            'yaa found by alef maqsura' => ['مصطفي كامل', 'مصطفى'],
            'extra spaces in the search text' => ['أحمد علي', '  احمد   علي '],
        ];
    }

    #[DataProvider('arabicVariants')]
    public function test_arabic_name_search_ignores_letter_variants(string $storedName, string $search): void
    {
        $match = Patient::factory()->create(['full_name' => $storedName]);
        Patient::factory()->create(['full_name' => 'Unrelated Person']);

        $this->getJson('/api/v1/patients?'.http_build_query(['name' => $search]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_like_wildcards_in_the_search_text_match_only_literally(): void
    {
        Patient::factory()->create(['full_name' => 'Ali Hassan']);

        $this->getJson('/api/v1/patients?name=_')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/patients?name=%25')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_phone_search_returns_every_patient_sharing_the_number(): void
    {
        Patient::factory()->count(2)->create(['phone' => '01000000001']);
        Patient::factory()->create(['phone' => '01000000009']);

        $this->getJson('/api/v1/patients?'.http_build_query(['phone' => '010 0000-0001']))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_national_id_and_patient_number_match_exactly(): void
    {
        $patient = Patient::factory()->create(['national_id' => '28502030100001']);
        Patient::factory()->withNationalId()->count(2)->create();

        $this->getJson('/api/v1/patients?national_id=28502030100001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);

        $this->getJson("/api/v1/patients?id={$patient->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $patient->id);
    }

    public function test_filters_combine_with_and(): void
    {
        $match = Patient::factory()->create(['full_name' => 'Sara Nabil', 'phone' => '01000000001']);
        Patient::factory()->create(['full_name' => 'Sara Kamal', 'phone' => '01000000002']);
        Patient::factory()->create(['full_name' => 'Omar Nabil', 'phone' => '01000000001']);

        $this->getJson('/api/v1/patients?name=sara&phone=01000000001')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_no_match_returns_an_empty_list(): void
    {
        Patient::factory()->create(['full_name' => 'Ali Hassan']);

        $this->getJson('/api/v1/patients?name=zzzz')
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    public function test_results_are_paged(): void
    {
        Patient::factory()->count(5)->create();

        $this->getJson('/api/v1/patients?per_page=2&page=3')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('meta.last_page', 3);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidFilters(): array
    {
        return [
            'per_page above 100' => ['per_page=101', 'per_page'],
            'per_page zero' => ['per_page=0', 'per_page'],
            'phone too short' => ['phone=1234', 'phone'],
            'national_id malformed' => ['national_id=123', 'national_id'],
            'id not a number' => ['id=abc', 'id'],
        ];
    }

    #[DataProvider('invalidFilters')]
    public function test_invalid_filters_are_rejected(string $query, string $field): void
    {
        $this->getJson("/api/v1/patients?{$query}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    public function test_list_items_never_include_sensitive_fields(): void
    {
        Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->count(2)->create();

        $items = $this->getJson('/api/v1/patients')->assertOk()->json('data');

        $this->assertCount(2, $items);

        foreach ($items as $item) {
            $this->assertEqualsCanonicalizing(self::SUMMARY_KEYS, array_keys($item));
        }
    }
}
