<?php

namespace Tests\Feature\Patients;

use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Opening one patient shows the full record, including sensitive fields (US2, FR-013).
 */
class ShowPatientTest extends TestCase
{
    use RefreshDatabase;

    public function test_receptionist_sees_the_full_record(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $patient = Patient::factory()->create([
            'full_name' => 'Synthetic Patient',
            'date_of_birth' => '1985-02-03',
            'sex' => 'female',
            'phone' => '01000000001',
            'national_id' => '28502030100001',
            'address' => '12 Synthetic St, Cairo',
            'emergency_contact_name' => 'Mona Ali',
            'emergency_contact_relationship' => 'sister',
            'emergency_contact_phone' => '01000000002',
        ]);

        $this->getJson("/api/v1/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $patient->id)
            ->assertJsonPath('data.full_name', 'Synthetic Patient')
            ->assertJsonPath('data.date_of_birth', '1985-02-03')
            ->assertJsonPath('data.sex', 'female')
            ->assertJsonPath('data.phone', '01000000001')
            ->assertJsonPath('data.national_id', '28502030100001')
            ->assertJsonPath('data.address', '12 Synthetic St, Cairo')
            ->assertJsonPath('data.emergency_contact', [
                'name' => 'Mona Ali',
                'relationship' => 'sister',
                'phone' => '01000000002',
            ])
            ->assertJsonMissingPath('data.name_search');
    }

    public function test_administrator_may_view_a_patient(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $patient = Patient::factory()->create();

        $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();
    }

    public function test_unknown_patient_number_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->getJson('/api/v1/patients/999999')
            ->assertNotFound()
            ->assertExactJson(['message' => __('patients::messages.not_found')]);
    }

    public function test_non_numeric_patient_number_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->getJson('/api/v1/patients/abc')->assertNotFound();
    }
}
