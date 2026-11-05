<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Exceptions;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * A refused attempt to open a medical record or history is recorded, and the response is exactly what
 * feature 008 requires (FR-005, SC-006, US2 scenarios 2 and 3, research R7).
 */
class AuditRefusedOpeningsTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private const PLANTED = 'PLANTED-CLINICAL';

    private Patient $patient;

    private Encounter $record;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->patient = Patient::factory()->create();
        $this->record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->seen()->create(['patient_id' => $this->patient->id, 'date' => '2026-10-15']))
            ->finalized()
            ->create(['diagnosis' => self::PLANTED]);
    }

    private function signInAsOtherDoctor(): Doctor
    {
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs($doctor->user);

        return $doctor;
    }

    public function test_a_doctor_refused_a_record_gets_the_same_body_as_for_an_unknown_id_and_it_is_recorded(): void
    {
        $doctor = $this->signInAsOtherDoctor();

        $refused = $this->getJson("/api/v1/doctors/me/encounters/{$this->record->id}")->assertNotFound();
        $unknown = $this->getJson('/api/v1/doctors/me/encounters/999999')->assertNotFound();

        $this->assertSame($unknown->getContent(), $refused->getContent());
        $this->assertOneEntry(AuditAction::EncounterOpened, [
            'outcome' => AuditOutcome::Refused,
            'actor_type' => AuditActorType::Staff,
            'actor_id' => $doctor->user_id,
            'actor_role' => 'doctor',
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $this->record->id,
            'patient_id' => $this->patient->id,
            'details' => null,
            'network_address' => '127.0.0.1',
        ]);
    }

    /**
     * @return array<string, array{Role}>
     */
    public static function rolesWithoutAccess(): array
    {
        return ['administrator' => [Role::Administrator], 'receptionist' => [Role::Receptionist]];
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_role_without_access_gets_403_and_the_attempt_is_recorded(Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson("/api/v1/doctors/me/encounters/{$this->record->id}")
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden')]);

        $this->assertOneEntry(AuditAction::EncounterOpened, ['outcome' => AuditOutcome::Refused, 'actor_role' => $role->value]);
    }

    public function test_a_doctor_refused_a_history_gets_the_same_body_as_for_an_unknown_patient_and_it_is_recorded(): void
    {
        $doctor = $this->signInAsOtherDoctor();

        $refused = $this->getJson("/api/v1/doctors/me/patients/{$this->patient->id}/encounters")->assertNotFound();
        $unknown = $this->getJson('/api/v1/doctors/me/patients/999999/encounters')->assertNotFound();

        $this->assertSame($unknown->getContent(), $refused->getContent());
        $this->assertOneEntry(AuditAction::EncounterHistoryViewed, [
            'outcome' => AuditOutcome::Refused,
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Patient,
            'subject_id' => $this->patient->id,
            'patient_id' => $this->patient->id,
        ]);
    }

    #[DataProvider('rolesWithoutAccess')]
    public function test_a_role_without_access_refused_a_history_is_recorded(Role $role): void
    {
        Sanctum::actingAs(User::factory()->create(['role' => $role]));

        $this->getJson("/api/v1/doctors/me/patients/{$this->patient->id}/encounters")->assertForbidden();

        $this->assertOneEntry(AuditAction::EncounterHistoryViewed, ['outcome' => AuditOutcome::Refused, 'actor_role' => $role->value]);
    }

    public function test_a_receptionist_with_an_invalid_filter_is_still_refused_and_recorded(): void
    {
        Sanctum::actingAs(User::factory()->receptionist()->create());

        // Authorization still runs before validation: a Form Request's refusal reaches the middleware.
        $this->getJson("/api/v1/doctors/me/patients/{$this->patient->id}/encounters?per_page=0")->assertForbidden();

        $this->assertOneEntry(AuditAction::EncounterHistoryViewed, ['outcome' => AuditOutcome::Refused]);
    }

    public function test_a_doctor_with_an_invalid_filter_gets_422_and_no_entry(): void
    {
        $this->signInAsOtherDoctor();

        $this->getJson("/api/v1/doctors/me/patients/{$this->patient->id}/encounters?per_page=0")->assertUnprocessable();

        $this->assertNoEntries(AuditAction::EncounterHistoryViewed);
    }

    public function test_an_unknown_id_writes_nothing(): void
    {
        $this->signInAsOtherDoctor();

        $this->getJson('/api/v1/doctors/me/encounters/999999')->assertNotFound();
        $this->getJson('/api/v1/doctors/me/patients/999999/encounters')->assertNotFound();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_a_patient_with_no_finalized_record_has_no_history_to_be_refused(): void
    {
        $patient = Patient::factory()->create();
        Encounter::factory()->forEntry(QueueEntry::factory()->withDoctor()->create(['patient_id' => $patient->id]))->create();
        $this->signInAsOtherDoctor();

        $this->getJson("/api/v1/doctors/me/patients/{$patient->id}/encounters")->assertNotFound();

        $this->assertNoEntries(AuditAction::EncounterHistoryViewed);
    }

    public function test_when_the_entry_fails_a_doctors_response_is_unchanged_and_the_failure_is_reported(): void
    {
        Exceptions::fake();
        $this->signInAsOtherDoctor();
        $this->failAuditWrites();

        $response = $this->getJson("/api/v1/doctors/me/encounters/{$this->record->id}")->assertNotFound();

        $this->assertSame($this->getJson('/api/v1/doctors/me/encounters/999999')->getContent(), $response->getContent());
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_when_the_entry_fails_a_receptionists_response_is_unchanged_and_the_failure_is_reported(): void
    {
        Exceptions::fake();
        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->failAuditWrites();

        $this->getJson("/api/v1/doctors/me/patients/{$this->patient->id}/encounters")
            ->assertForbidden()
            ->assertExactJson(['message' => __('auth.forbidden')]);

        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_refused_changes_write_no_entry(): void
    {
        $this->signInAsOtherDoctor();
        $id = $this->record->id;

        $this->putJson("/api/v1/doctors/me/encounters/{$id}", [
            'chief_complaint' => null,
            'examination_findings' => null,
            'diagnosis' => null,
            'treatment_plan' => null,
            'prescriptions' => [],
        ])->assertNotFound();
        $this->postJson("/api/v1/doctors/me/encounters/{$id}/finalize")->assertNotFound();
        $this->postJson("/api/v1/doctors/me/encounters/{$id}/addenda", ['text' => 'Synthetic'])->assertNotFound();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_a_refused_entry_holds_no_clinical_content(): void
    {
        $this->signInAsOtherDoctor();

        $this->getJson("/api/v1/doctors/me/encounters/{$this->record->id}")->assertNotFound();

        $this->assertStringNotContainsString(self::PLANTED, json_encode(AuditEntry::query()->sole()->getAttributes()));
    }
}
