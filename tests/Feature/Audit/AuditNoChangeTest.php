<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * FR-007: an update that changes nothing writes nothing (US1 scenario 5). An action that always records
 * something new, such as setting a discount, still writes (spec, Edge Cases).
 */
class AuditNoChangeTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    private function entriesFor(AuditAction $action): int
    {
        return AuditEntry::query()->where('action', $action)->count();
    }

    /* Patients (003) */

    public function test_the_same_patient_update_twice_writes_one_entry(): void
    {
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000002'])->assertOk();
        $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000002'])->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::PatientUpdated));
    }

    /* Money (009) */

    public function test_the_same_fee_twice_writes_one_entry(): void
    {
        $doctor = Doctor::factory()->create();

        $this->putJson("/api/v1/doctors/{$doctor->id}/fee", ['amount' => '350.00'])->assertOk();
        $this->putJson("/api/v1/doctors/{$doctor->id}/fee", ['amount' => '350'])->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::FeeSet));
    }

    public function test_the_same_discount_twice_writes_two_entries(): void
    {
        $charge = Charge::factory()->create(['fee' => '300.00']);

        $this->putJson("/api/v1/charges/{$charge->id}/discount", ['amount' => '50.00', 'reason' => 'Synthetic'])->assertOk();
        $this->putJson("/api/v1/charges/{$charge->id}/discount", ['amount' => '50.00', 'reason' => 'Synthetic'])->assertOk();

        $this->assertSame(2, $this->entriesFor(AuditAction::DiscountSet));
    }

    /* Doctors (004) */

    public function test_the_same_rename_twice_writes_one_entry(): void
    {
        $specialty = Specialty::factory()->create();

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'Synthetic Renamed'])->assertOk();
        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'Synthetic Renamed'])->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::SpecialtyUpdated));
    }

    public function test_deactivating_and_reactivating_twice_writes_one_entry_each(): void
    {
        $specialty = Specialty::factory()->create();

        $this->postJson("/api/v1/specialties/{$specialty->id}/deactivate")->assertOk();
        $this->postJson("/api/v1/specialties/{$specialty->id}/deactivate")->assertOk();
        $this->postJson("/api/v1/specialties/{$specialty->id}/reactivate")->assertOk();
        $this->postJson("/api/v1/specialties/{$specialty->id}/reactivate")->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::SpecialtyDeactivated));
        $this->assertSame(1, $this->entriesFor(AuditAction::SpecialtyReactivated));
    }

    public function test_the_same_doctor_update_twice_writes_one_entry(): void
    {
        $doctor = Doctor::factory()->create();
        $payload = ['title' => 'Synthetic Title', 'specialty_ids' => [Specialty::factory()->create()->id]];

        $this->patchJson("/api/v1/doctors/{$doctor->id}", $payload)->assertOk();
        $this->patchJson("/api/v1/doctors/{$doctor->id}", $payload)->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::DoctorUpdated));
    }

    /* Schedules (005) */

    public function test_the_same_schedule_in_another_order_writes_one_entry(): void
    {
        $doctor = Doctor::factory()->create();
        $monday = ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'];
        $tuesday = ['day' => 'tuesday', 'start' => '10:00', 'end' => '11:00'];

        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 20, 'periods' => [$monday, $tuesday]])->assertOk();
        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 20, 'periods' => [$tuesday, $monday]])->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::ScheduleSet));
    }

    public function test_changing_only_the_appointment_length_or_only_the_periods_writes_an_entry(): void
    {
        $doctor = Doctor::factory()->create();
        $monday = ['day' => 'monday', 'start' => '09:00', 'end' => '12:00'];

        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 20, 'periods' => [$monday]])->assertOk();
        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 30, 'periods' => [$monday]])->assertOk();
        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 30, 'periods' => []])->assertOk();

        $this->assertSame(3, $this->entriesFor(AuditAction::ScheduleSet));
    }

    public function test_clearing_a_doctor_with_no_schedule_writes_nothing(): void
    {
        $doctor = Doctor::factory()->create();

        $this->deleteJson("/api/v1/doctors/{$doctor->id}/schedule")->assertSuccessful();

        $this->assertSame(0, $this->entriesFor(AuditAction::ScheduleCleared));
    }

    /* Medical records (008) */

    public function test_saving_the_same_content_twice_writes_one_entry(): void
    {
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs($doctor->user);
        $visit = QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id]);
        $encounter = Encounter::factory()->forEntry($visit)->create();
        $content = [
            'chief_complaint' => 'Synthetic',
            'examination_findings' => null,
            'diagnosis' => 'Synthetic',
            'treatment_plan' => null,
            'prescriptions' => [['medication' => 'Synthetic']],
        ];

        $this->putJson("/api/v1/doctors/me/encounters/{$encounter->id}", $content)->assertOk();
        $this->putJson("/api/v1/doctors/me/encounters/{$encounter->id}", $content)->assertOk();

        $this->assertSame(1, $this->entriesFor(AuditAction::EncounterDraftSaved));
    }
}
