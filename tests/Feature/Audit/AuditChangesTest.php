<?php

namespace Tests\Feature\Audit;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Support\AuditChanges;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\Charge;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What an entry may say about a change: names always, values only from the allow-list (FR-006,
 * research R4, R5). Models are reloaded with fresh(), because a factory-created model still counts
 * as newly created.
 */
class AuditChangesTest extends TestCase
{
    use RefreshDatabase;

    public function test_money_and_status_keep_their_values_and_a_reason_keeps_only_its_name(): void
    {
        $charge = Charge::factory()->create()->fresh();

        $charge->update([
            'discount' => '200.00',
            'discount_reason' => 'PLANTED-REASON',
            'discounted_by' => User::factory()->administrator()->create()->id,
            'discounted_at' => now(),
            'status' => ChargeStatus::Paid,
        ]);

        $changes = AuditChanges::of($charge, AuditSubject::Charge);

        $this->assertEqualsCanonicalizing(['discount', 'discount_reason', 'status'], $changes['fields']);
        $this->assertSame(['from' => '0.00', 'to' => '200.00'], $changes['values']['discount']);
        $this->assertSame(['from' => 'unpaid', 'to' => 'paid'], $changes['values']['status']);
        $this->assertArrayNotHasKey('discount_reason', $changes['values']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($changes));
    }

    public function test_a_patient_keeps_field_names_only(): void
    {
        $patient = Patient::factory()->create()->fresh();

        $patient->update(['phone' => '01000000099', 'full_name' => 'Planted Name']);

        $changes = AuditChanges::of($patient, AuditSubject::Patient);

        $this->assertEqualsCanonicalizing(['phone', 'full_name'], $changes['fields']);
        $this->assertSame([], $changes['values']);
        $this->assertStringNotContainsString('01000000099', json_encode($changes));
        $this->assertStringNotContainsString('Planted', json_encode($changes));
    }

    public function test_an_encrypted_field_gives_its_name_and_never_text_or_ciphertext(): void
    {
        $encounter = Encounter::factory()->create(['diagnosis' => 'first'])->fresh();

        $encounter->update(['diagnosis' => 'PLANTED-DIAGNOSIS']);

        $changes = AuditChanges::of($encounter, AuditSubject::Encounter);
        $json = json_encode($changes);

        $this->assertSame(['diagnosis'], $changes['fields']);
        $this->assertSame([], $changes['values']);
        $this->assertStringNotContainsString('PLANTED-DIAGNOSIS', $json);
        $this->assertStringNotContainsString($encounter->getRawOriginal('diagnosis'), $json);
    }

    public function test_an_encrypted_field_saved_with_the_same_text_is_no_change(): void
    {
        $encounter = Encounter::factory()->create(['diagnosis' => 'same'])->fresh();

        $encounter->update(['diagnosis' => 'same']);

        $this->assertSame([], AuditChanges::of($encounter, AuditSubject::Encounter)['fields']);
    }

    public function test_an_encounter_keeps_its_status_values(): void
    {
        $encounter = Encounter::factory()->create()->fresh();

        $encounter->update(['status' => EncounterStatus::Finalized, 'finalized_at' => now()]);

        $this->assertSame(
            ['status' => ['from' => 'draft', 'to' => 'finalized']],
            AuditChanges::of($encounter, AuditSubject::Encounter)['values'],
        );
    }

    public function test_a_moved_appointment_keeps_its_times(): void
    {
        $appointment = Appointment::factory()->at('2026-12-01', '09:00')->create()->fresh();

        $appointment->update(['date' => '2026-12-02', 'start_minute' => 600, 'end_minute' => 620]);

        $this->assertSame([
            'date' => ['from' => '2026-12-01', 'to' => '2026-12-02'],
            'start_minute' => ['from' => 540, 'to' => 600],
            'end_minute' => ['from' => 560, 'to' => 620],
        ], AuditChanges::of($appointment, AuditSubject::Appointment)['values']);
    }

    public function test_a_staff_member_keeps_role_and_status_values_and_only_the_name_of_a_password(): void
    {
        $user = User::factory()->receptionist()->create()->fresh();

        $user->update(['role' => Role::Doctor, 'is_active' => false, 'password' => 'Planted-Password-1']);

        $changes = AuditChanges::of($user, AuditSubject::StaffMember);

        $this->assertEqualsCanonicalizing(['role', 'is_active', 'password'], $changes['fields']);
        $this->assertSame(['from' => 'receptionist', 'to' => 'doctor'], $changes['values']['role']);
        $this->assertSame(['from' => true, 'to' => false], $changes['values']['is_active']);
        $this->assertArrayNotHasKey('password', $changes['values']);
        $this->assertStringNotContainsString($user->getRawOriginal('password'), json_encode($changes));
    }

    public function test_a_new_model_is_a_creation(): void
    {
        $charge = Charge::factory()->create();

        $changes = AuditChanges::of($charge, AuditSubject::Charge);

        $this->assertContains('fee', $changes['fields']);
        $this->assertSame(['from' => null, 'to' => '300.00'], $changes['values']['fee']);
        $this->assertNotContains('id', $changes['fields']);
        $this->assertNotContains('charged_by', $changes['fields']);
        $this->assertNotContains('created_at', $changes['fields']);
    }

    public function test_a_derived_column_is_never_named(): void
    {
        $patient = Patient::factory()->create()->fresh();

        $patient->update(['full_name' => 'Another Synthetic Name']);

        $this->assertNotContains('name_search', AuditChanges::of($patient, AuditSubject::Patient)['fields']);
    }

    public function test_a_type_with_no_allowed_values_keeps_names_only(): void
    {
        $charge = Charge::factory()->create()->fresh();

        $charge->update(['discount' => '10.00']);

        $changes = AuditChanges::of($charge, AuditSubject::TimeOff);

        $this->assertSame(['discount'], $changes['fields']);
        $this->assertSame([], $changes['values']);
    }

    public function test_no_change_gives_nothing(): void
    {
        $patient = Patient::factory()->create()->fresh();

        $this->assertSame(['fields' => [], 'values' => []], AuditChanges::of($patient, AuditSubject::Patient));
    }
}
