<?php

namespace Tests\Feature\Doctors;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Doctors\Services\DoctorService;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Administrators create doctor profiles for doctor-role staff accounts (US2, FR-005–FR-009,
 * FR-011, FR-015).
 */
class CreateDoctorTest extends TestCase
{
    use RefreshDatabase;

    private const DOCTOR_KEYS = [
        'id', 'name', 'title', 'is_active', 'specialties', 'registration_number', 'created_at', 'updated_at',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    public function test_administrator_creates_a_profile_for_a_doctor_account(): void
    {
        $account = User::factory()->doctor()->create(['name' => 'Synthetic Doctor']);
        $specialties = Specialty::factory()->count(2)->create();

        $response = $this->postJson('/api/v1/doctors', $this->payload($account, [
            'specialty_ids' => $specialties->modelKeys(),
        ]))
            ->assertCreated()
            ->assertJsonPath('data.name', 'Synthetic Doctor')
            ->assertJsonPath('data.title', 'Consultant')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.registration_number', null)
            ->assertJsonCount(2, 'data.specialties')
            ->assertJsonMissingPath('data.email')
            ->assertJsonMissingPath('data.role')
            ->assertJsonMissingPath('data.user_id');

        $this->assertEqualsCanonicalizing(self::DOCTOR_KEYS, array_keys($response->json('data')));
        $this->assertEqualsCanonicalizing(['id', 'name_en', 'name_ar', 'is_active'], array_keys($response->json('data.specialties.0')));
        $this->assertDatabaseCount('doctors', 1);
        $this->assertDatabaseCount('doctor_specialty', 2);
    }

    public function test_the_title_is_stored_cleaned(): void
    {
        $this->postJson('/api/v1/doctors', $this->payload(null, ['title' => '  Senior   Consultant ']))
            ->assertCreated()
            ->assertJsonPath('data.title', 'Senior Consultant');
    }

    public function test_a_registration_number_is_stored_and_must_be_unique(): void
    {
        $this->postJson('/api/v1/doctors', $this->payload(null, ['registration_number' => 'A12345']))
            ->assertCreated()
            ->assertJsonPath('data.registration_number', 'A12345');

        $this->postJson('/api/v1/doctors', $this->payload(null, ['registration_number' => 'a12345']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['registration_number']);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidFields(): array
    {
        return [
            'registration number with a dash' => [['registration_number' => 'A-123'], 'registration_number'],
            'registration number too long' => [['registration_number' => str_repeat('A', 31)], 'registration_number'],
            'title missing' => [['title' => null], 'title'],
            'title too long' => [['title' => str_repeat('a', 101)], 'title'],
            'specialties missing' => [['specialty_ids' => null], 'specialty_ids'],
            'specialties empty' => [['specialty_ids' => []], 'specialty_ids'],
            'specialties not a list' => [['specialty_ids' => 'x'], 'specialty_ids'],
            'unknown specialty' => [['specialty_ids' => [999]], 'specialty_ids.0'],
            'unknown account' => [['user_id' => 999], 'user_id'],
            'account missing' => [['user_id' => null], 'user_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidFields')]
    public function test_invalid_fields_are_rejected(array $overrides, string $field): void
    {
        $this->postJson('/api/v1/doctors', $this->payload(null, $overrides))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$field]);

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_duplicate_specialties_are_rejected(): void
    {
        $specialty = Specialty::factory()->create();

        $this->postJson('/api/v1/doctors', $this->payload(null, ['specialty_ids' => [$specialty->id, $specialty->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['specialty_ids.0']);
    }

    public function test_limits_of_100_and_30_characters_are_accepted(): void
    {
        $this->postJson('/api/v1/doctors', $this->payload(null, [
            'title' => str_repeat('a', 100),
            'registration_number' => str_repeat('A', 30),
        ]))->assertCreated();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function accountsThatAreNotActiveDoctors(): array
    {
        return [
            'receptionist' => ['receptionist'],
            'administrator' => ['administrator'],
            'inactive doctor' => ['inactive doctor'],
        ];
    }

    #[DataProvider('accountsThatAreNotActiveDoctors')]
    public function test_the_account_must_be_an_active_doctor(string $kind): void
    {
        $account = match ($kind) {
            'receptionist' => User::factory()->receptionist()->create(),
            'administrator' => User::factory()->administrator()->create(),
            'inactive doctor' => User::factory()->doctor()->inactive()->create(),
        };

        $this->postJson('/api/v1/doctors', $this->payload($account))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id' => __('doctors::messages.not_an_active_doctor')]);

        $this->assertDatabaseCount('doctors', 0);
    }

    public function test_an_account_can_have_only_one_profile(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create();

        $this->postJson('/api/v1/doctors', $this->payload($doctor->user))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['user_id']);

        $this->assertDatabaseCount('doctors', 1);
    }

    public function test_a_deactivated_specialty_cannot_be_assigned(): void
    {
        $active = Specialty::factory()->create();
        $inactive = Specialty::factory()->inactive()->create();

        $this->postJson('/api/v1/doctors', $this->payload(null, ['specialty_ids' => [$active->id, $inactive->id]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['specialty_ids' => __('doctors::messages.specialty_inactive')]);

        $this->assertDatabaseCount('doctors', 0);
        $this->assertDatabaseCount('doctor_specialty', 0);
    }

    public function test_a_simultaneous_second_profile_for_one_account_is_a_validation_error(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create();

        $this->assertServiceRejects('user_id', [
            'user_id' => $doctor->user_id,
            'title' => 'Consultant',
            'specialty_ids' => [Specialty::factory()->create()->id],
        ]);

        $this->assertDatabaseCount('doctors', 1);
    }

    public function test_a_simultaneous_duplicate_registration_number_is_a_validation_error(): void
    {
        Doctor::factory()->withSpecialties()->create(['registration_number' => 'A12345']);

        $this->assertServiceRejects('registration_number', [
            'user_id' => User::factory()->doctor()->create()->id,
            'title' => 'Consultant',
            'registration_number' => 'A12345',
            'specialty_ids' => [Specialty::factory()->create()->id],
        ]);

        $this->assertDatabaseCount('doctors', 1);
        $this->assertDatabaseCount('doctor_specialty', 1);
    }

    /**
     * Call the service directly, as a request that passed validation before the other one was
     * written would (research R7).
     *
     * @param  array<string, mixed>  $attributes
     */
    private function assertServiceRejects(string $field, array $attributes): void
    {
        try {
            app(DoctorService::class)->create($attributes);
            $this->fail('A duplicate was stored.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(?User $account = null, array $overrides = []): array
    {
        return [
            'user_id' => ($account ?? User::factory()->doctor()->create())->id,
            'title' => 'Consultant',
            'specialty_ids' => [Specialty::factory()->create()->id],
            ...$overrides,
        ];
    }
}
