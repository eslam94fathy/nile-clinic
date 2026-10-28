<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Receptionists and administrators correct and update patient details (US3, FR-010).
 */
class UpdatePatientTest extends TestCase
{
    use RefreshDatabase;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->patient = Patient::factory()->withAddress()->withEmergencyContact()->create([
            'full_name' => 'Synthetic Patient',
            'phone' => '01000000001',
            'national_id' => '28502030100001',
        ]);
    }

    public function test_only_the_sent_field_changes(): void
    {
        $before = $this->patient->fresh();
        $this->travel(1)->minutes();

        $this->patchJson($this->url(), ['phone' => '0100 000 0009'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->patient->id)
            ->assertJsonPath('data.phone', '01000000009')
            ->assertJsonPath('data.full_name', 'Synthetic Patient')
            ->assertJsonPath('data.national_id', '28502030100001')
            ->assertJsonPath('data.address', $before->address);

        $after = $this->patient->fresh();
        $this->assertTrue($after->updated_at->greaterThan($before->updated_at));
        $this->assertSame($before->created_at->toIso8601String(), $after->created_at->toIso8601String());
    }

    public function test_changing_the_name_updates_search(): void
    {
        $this->patchJson($this->url(), ['full_name' => '  فاطمة   يوسف '])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'فاطمة يوسف');

        $this->getJson('/api/v1/patients?'.http_build_query(['name' => 'فاطمه']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->patient->id);
    }

    public function test_optional_details_can_be_cleared(): void
    {
        $this->patchJson($this->url(), ['national_id' => null, 'address' => null, 'emergency_contact' => null])
            ->assertOk()
            ->assertJsonPath('data.national_id', null)
            ->assertJsonPath('data.address', null)
            ->assertJsonPath('data.emergency_contact', null);

        $this->assertDatabaseHas('patients', [
            'id' => $this->patient->id,
            'national_id' => null,
            'address' => null,
            'emergency_contact_name' => null,
            'emergency_contact_relationship' => null,
            'emergency_contact_phone' => null,
        ]);
    }

    public function test_an_emergency_contact_can_be_added(): void
    {
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", [
            'emergency_contact' => ['name' => 'Mona Ali', 'relationship' => 'sister', 'phone' => '010-0000-0010'],
        ])
            ->assertOk()
            ->assertJsonPath('data.emergency_contact', [
                'name' => 'Mona Ali',
                'relationship' => 'sister',
                'phone' => '01000000010',
            ]);
    }

    public function test_the_patients_own_national_id_can_be_sent_again(): void
    {
        $this->patchJson($this->url(), ['national_id' => '28502030100001', 'full_name' => 'Synthetic Patient Two'])
            ->assertOk()
            ->assertJsonPath('data.national_id', '28502030100001');
    }

    public function test_another_patients_national_id_is_rejected(): void
    {
        Patient::factory()->create(['national_id' => '29001010100002']);

        $this->patchJson($this->url(), ['national_id' => '29001010100002'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('national_id');

        $this->assertSame('28502030100001', $this->patient->fresh()->national_id);
    }

    public function test_invalid_values_are_rejected_and_nothing_changes(): void
    {
        $this->patchJson($this->url(), ['phone' => '12', 'date_of_birth' => now()->addDay()->toDateString()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['phone', 'date_of_birth']);

        $this->assertSame('01000000001', $this->patient->fresh()->phone);
    }

    public function test_required_fields_cannot_be_cleared(): void
    {
        $this->patchJson($this->url(), ['full_name' => null, 'phone' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['full_name', 'phone']);
    }

    public function test_an_empty_update_is_rejected(): void
    {
        $this->patchJson($this->url(), [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['fields' => __('patients::messages.nothing_to_update')]);

        $this->patchJson($this->url(), ['unknown' => 'value'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fields');
    }

    public function test_id_and_name_search_cannot_be_set(): void
    {
        $this->patchJson($this->url(), ['id' => 999, 'name_search' => 'hacked', 'phone' => '01000000003'])
            ->assertOk()
            ->assertJsonPath('data.id', $this->patient->id);

        $this->assertSame('synthetic patient', $this->patient->fresh()->name_search);
        $this->assertDatabaseMissing('patients', ['id' => 999]);
    }

    public function test_administrator_may_update_a_patient(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->patchJson($this->url(), ['address' => 'New Synthetic Address'])->assertOk();
    }

    public function test_unknown_patient_number_is_not_found(): void
    {
        $this->patchJson('/api/v1/patients/999999', ['phone' => '01000000003'])->assertNotFound();
    }

    private function url(): string
    {
        return "/api/v1/patients/{$this->patient->id}";
    }
}
