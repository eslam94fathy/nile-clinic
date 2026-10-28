<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Services\PatientService;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Receptionists and administrators register patients (US1, FR-001–FR-006, FR-009).
 */
class RegisterPatientTest extends TestCase
{
    use RefreshDatabase;

    private const PATIENT_KEYS = [
        'id', 'full_name', 'sex', 'date_of_birth', 'phone', 'national_id', 'address',
        'emergency_contact', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeTime();
        Sanctum::actingAs(User::factory()->receptionist()->create());
    }

    public function test_receptionist_registers_a_patient_with_required_details_only(): void
    {
        $response = $this->postJson('/api/v1/patients', $this->payload([
            'full_name' => '  أحمد   محمود ',
            'phone' => '010-0000 0001',
        ]))
            ->assertCreated()
            ->assertJsonPath('data.full_name', 'أحمد محمود')
            ->assertJsonPath('data.date_of_birth', '1985-02-03')
            ->assertJsonPath('data.sex', 'male')
            ->assertJsonPath('data.phone', '01000000001')
            ->assertJsonPath('data.national_id', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.emergency_contact', null)
            ->assertJsonMissingPath('data.name_search');

        $this->assertEqualsCanonicalizing(self::PATIENT_KEYS, array_keys($response->json('data')));

        $patient = Patient::query()->findOrFail($response->json('data.id'));
        $this->assertSame('احمد محمود', $patient->name_search);
    }

    public function test_all_details_are_stored_and_returned(): void
    {
        $this->postJson('/api/v1/patients', $this->payload([
            'national_id' => '28502030100001',
            'address' => '12 Synthetic St, Cairo',
            'emergency_contact' => ['name' => 'منى محمود', 'relationship' => 'sister', 'phone' => '0100 000 0002'],
        ]))
            ->assertCreated()
            ->assertJsonPath('data.national_id', '28502030100001')
            ->assertJsonPath('data.address', '12 Synthetic St, Cairo')
            ->assertJsonPath('data.emergency_contact', [
                'name' => 'منى محمود',
                'relationship' => 'sister',
                'phone' => '01000000002',
            ]);

        $this->assertDatabaseHas('patients', [
            'national_id' => '28502030100001',
            'emergency_contact_name' => 'منى محمود',
            'emergency_contact_relationship' => 'sister',
            'emergency_contact_phone' => '01000000002',
        ]);
    }

    public function test_administrator_may_register_a_patient(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->postJson('/api/v1/patients', $this->payload())->assertCreated();
    }

    public function test_boundary_values_are_accepted(): void
    {
        $this->postJson('/api/v1/patients', $this->payload([
            'date_of_birth' => now()->toDateString(),
            'phone' => '12345678',
        ]))->assertCreated();

        $this->postJson('/api/v1/patients', $this->payload([
            'date_of_birth' => now()->subYears(130)->toDateString(),
            'phone' => '+123456789012345',
            'national_id' => '30001010100001',
            'address' => str_repeat('a', 500),
        ]))->assertCreated();
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidPayloads(): array
    {
        return [
            'full_name missing' => [['full_name' => null], 'full_name'],
            'date_of_birth missing' => [['date_of_birth' => null], 'date_of_birth'],
            'sex missing' => [['sex' => null], 'sex'],
            'phone missing' => [['phone' => null], 'phone'],
            'sex other' => [['sex' => 'other'], 'sex'],
            'date_of_birth wrong format' => [['date_of_birth' => '03/02/1985'], 'date_of_birth'],
            'phone 7 digits' => [['phone' => '1234567'], 'phone'],
            'phone 16 digits' => [['phone' => '1234567890123456'], 'phone'],
            'phone letters' => [['phone' => '0100abc0001'], 'phone'],
            'national_id 13 digits' => [['national_id' => '2850203010000'], 'national_id'],
            'national_id 15 digits' => [['national_id' => '285020301000011'], 'national_id'],
            'national_id letters' => [['national_id' => '2850203010000a'], 'national_id'],
            'national_id first digit 1' => [['national_id' => '18502030100001'], 'national_id'],
            'address 501 characters' => [['address' => str_repeat('a', 501)], 'address'],
            'emergency_contact empty object' => [['emergency_contact' => []], 'emergency_contact'],
            'emergency_contact phone missing' => [['emergency_contact' => ['name' => 'Mona', 'relationship' => 'sister']], 'emergency_contact'],
            'emergency_contact phone invalid' => [['emergency_contact' => ['name' => 'Mona', 'relationship' => 'sister', 'phone' => '12']], 'emergency_contact.phone'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidPayloads')]
    public function test_invalid_details_are_rejected_and_nothing_is_created(array $overrides, string $field): void
    {
        $this->postJson('/api/v1/patients', $this->payload($overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('patients', 0);
    }

    public function test_date_of_birth_in_the_future_is_rejected(): void
    {
        $this->postJson('/api/v1/patients', $this->payload(['date_of_birth' => now()->addDay()->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_date_of_birth_more_than_130_years_ago_is_rejected(): void
    {
        $this->postJson('/api/v1/patients', $this->payload(['date_of_birth' => now()->subYears(130)->subDay()->toDateString()]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date_of_birth');
    }

    public function test_national_id_of_another_patient_is_rejected(): void
    {
        Patient::factory()->create(['national_id' => '28502030100001']);

        $this->postJson('/api/v1/patients', $this->payload(['national_id' => '28502030100001']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');

        $this->assertDatabaseCount('patients', 1);
    }

    public function test_simultaneous_registration_with_the_same_national_id_is_rejected(): void
    {
        // The second of two racing requests passes validation; the unique index must stop it (SC-006).
        Patient::factory()->create(['national_id' => '28502030100001']);

        try {
            app(PatientService::class)->register($this->payload(['national_id' => '28502030100001']));
            $this->fail('A duplicate national ID was stored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('national_id', $e->errors());
        }

        $this->assertSame(1, Patient::query()->where('national_id', '28502030100001')->count());
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'full_name' => 'Synthetic Patient',
            'date_of_birth' => '1985-02-03',
            'sex' => 'male',
            'phone' => '01000000001',
            ...$overrides,
        ];
    }
}
