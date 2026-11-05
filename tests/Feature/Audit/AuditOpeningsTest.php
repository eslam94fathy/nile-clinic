<?php

namespace Tests\Feature\Audit;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * Opening a patient's details, a medical record, or a medical history leaves an entry, and without one
 * the record is not returned (FR-004, FR-007, US2, US4 scenario 3). Listings leave none.
 */
class AuditOpeningsTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private const PLANTED = 'PLANTED-CLINICAL';

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
    }

    public function test_opening_another_doctors_permitted_record_is_recorded(): void
    {
        $reader = Doctor::factory()->create();
        $patient = Patient::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $reader->id, 'patient_id' => $patient->id]);
        $record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->seen()->create(['patient_id' => $patient->id, 'date' => '2026-10-15']))
            ->finalized()
            ->create();
        Sanctum::actingAs($reader->user);

        $this->getJson("/api/v1/doctors/me/encounters/{$record->id}")->assertOk();

        $this->assertOneEntry(AuditAction::EncounterOpened, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_id' => $reader->user_id,
            'patient_id' => $patient->id,
        ]);
    }

    public function test_an_entry_about_an_opened_record_holds_no_clinical_content(): void
    {
        $doctor = Doctor::factory()->create();
        $record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]))
            ->create(['diagnosis' => self::PLANTED]);
        Sanctum::actingAs($doctor->user);

        $this->getJson("/api/v1/doctors/me/encounters/{$record->id}")->assertOk()->assertSee(self::PLANTED);

        $this->assertNull($this->assertOneEntry(AuditAction::EncounterOpened)->details);
    }

    public function test_history_is_recorded_once_per_page_requested(): void
    {
        $doctor = Doctor::factory()->create();
        $patient = Patient::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);
        Sanctum::actingAs($doctor->user);

        // Nothing on record yet, but the patient is on the doctor's list, so this is an empty 200.
        $this->getJson("/api/v1/doctors/me/patients/{$patient->id}/encounters")->assertOk()->assertJsonPath('data', []);
        $this->getJson("/api/v1/doctors/me/patients/{$patient->id}/encounters?page=2")->assertOk();

        $this->assertSame(2, AuditEntry::query()->where('action', AuditAction::EncounterHistoryViewed)->where('subject_id', $patient->id)->count());
    }

    public function test_a_patient_is_not_returned_when_the_entry_fails(): void
    {
        $patient = Patient::factory()->create(['full_name' => 'Planted Patient Name', 'phone' => '01000000077']);
        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->failAuditWrites();

        $response = $this->getJson("/api/v1/patients/{$patient->id}")->assertStatus(500);

        $this->assertStringNotContainsString('Planted Patient Name', $response->getContent());
        $this->assertStringNotContainsString('01000000077', $response->getContent());
    }

    public function test_a_medical_record_is_not_returned_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->create();
        $record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]))
            ->create(['diagnosis' => self::PLANTED]);
        Sanctum::actingAs($doctor->user);
        $this->failAuditWrites();

        $response = $this->getJson("/api/v1/doctors/me/encounters/{$record->id}")->assertStatus(500);

        $this->assertStringNotContainsString(self::PLANTED, $response->getContent());
    }

    public function test_a_medical_history_is_not_returned_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->create();
        $patient = Patient::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);
        Sanctum::actingAs($doctor->user);
        $this->failAuditWrites();

        $this->getJson("/api/v1/doctors/me/patients/{$patient->id}/encounters")->assertStatus(500)->assertJsonMissingPath('data');
    }

    public function test_listings_and_reports_and_opening_a_charge_write_nothing(): void
    {
        $doctor = Doctor::factory()->create();
        $charge = Charge::factory()->create();
        Sanctum::actingAs(User::factory()->administrator()->create());

        foreach ([
            '/api/v1/patients',
            '/api/v1/appointments?from=2026-11-01&to=2026-11-02',
            '/api/v1/queue',
            '/api/v1/charges?from=2026-11-01&to=2026-11-02',
            "/api/v1/charges/{$charge->id}",
            '/api/v1/reports/money-collected?from=2026-11-01&to=2026-11-01',
            '/api/v1/reports/billing?from=2026-11-01&to=2026-11-01',
            '/api/v1/reports/activity?from=2026-11-01&to=2026-11-01',
            '/api/v1/reports/times?from=2026-11-01&to=2026-11-01',
        ] as $url) {
            $this->getJson($url)->assertOk();
        }

        Sanctum::actingAs($doctor->user);
        $this->getJson('/api/v1/doctors/me/visits?from=2026-11-01&to=2026-11-02')->assertOk();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    public function test_searching_by_patient_finds_entries_from_every_kind_of_record(): void
    {
        $patient = Patient::factory()->create();
        $doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $doctor->id]);
        $appointment = Appointment::factory()->at('2026-11-02', '09:00')->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
        $administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($administrator);

        $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();
        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")->assertOk();
        $visit = $this->postJson('/api/v1/queue/walk-ins', ['patient_id' => $patient->id, 'doctor_id' => $doctor->id])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/visits/{$visit}/charge")->assertCreated();

        Sanctum::actingAs($doctor->user);
        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertOk();
        $this->postJson("/api/v1/doctors/me/visits/{$visit}/encounter")->assertCreated();

        // Someone else's activity must not appear.
        AuditEntry::factory()->create(['patient_id' => Patient::factory()->create()->id, 'action' => AuditAction::PatientOpened]);

        Sanctum::actingAs($administrator);

        $actions = array_column($this->getJson("/api/v1/audit-entries?patient_id={$patient->id}")->assertOk()->json('data'), 'action');

        $this->assertSame([
            'encounter.started',
            'visit.called',
            'charge.created',
            'visit.walk_in_added',
            'appointment.cancelled',
            'patient.opened',
        ], $actions);

        $subjects = AuditEntry::query()->where('patient_id', $patient->id)->pluck('subject_type')->unique();
        $this->assertTrue($subjects->contains(AuditSubject::Patient) && $subjects->contains(AuditSubject::Encounter));
    }

    /**
     * An update that changes nothing still returns the whole record, so it must leave a trace, or sending
     * one known field would read the rest of a patient's details unseen. An empty body is refused (003).
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function updatesThatChangeNothing(): array
    {
        return [
            'the current phone' => [['phone' => '01000000077']],
            'the current name' => [['full_name' => 'Synthetic Current Name']],
        ];
    }

    /**
     * @param  array<string, string>  $body
     */
    #[DataProvider('updatesThatChangeNothing')]
    public function test_an_update_that_changes_nothing_is_recorded_as_an_opening(array $body): void
    {
        $patient = Patient::factory()->create(['full_name' => 'Synthetic Current Name', 'phone' => '01000000077']);
        $receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($receptionist);

        $this->patchJson("/api/v1/patients/{$patient->id}", $body)
            ->assertOk()
            ->assertJsonPath('data.phone', '01000000077');

        $this->assertOneEntry(AuditAction::PatientOpened, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_id' => $receptionist->id,
            'subject_type' => AuditSubject::Patient,
            'subject_id' => $patient->id,
            'patient_id' => $patient->id,
        ]);
        $this->assertNoEntries(AuditAction::PatientUpdated);
    }

    public function test_an_update_that_changes_nothing_returns_no_details_when_the_entry_fails(): void
    {
        $patient = Patient::factory()->create(['full_name' => 'Planted Patient Name', 'phone' => '01000000077']);
        Sanctum::actingAs(User::factory()->receptionist()->create());
        $this->failAuditWrites();

        $response = $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000077'])->assertStatus(500);

        $this->assertStringNotContainsString('Planted Patient Name', $response->getContent());
    }

    public function test_an_update_that_changes_something_is_one_update_and_no_opening(): void
    {
        $patient = Patient::factory()->create();
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000002'])->assertOk();

        $this->assertOneEntry(AuditAction::PatientUpdated);
        $this->assertNoEntries(AuditAction::PatientOpened);
    }
}
