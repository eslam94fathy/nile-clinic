<?php

namespace Tests\Feature\Audit;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Enums\AuditActorType;
use App\Modules\Audit\Enums\AuditOutcome;
use App\Modules\Audit\Enums\AuditSubject;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * SC-001: every action, driven through its real endpoint, writes exactly one correct entry. One method
 * per action, named test_ plus the action with its dot replaced.
 */
class AuditActionCoverageTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->administrator = User::factory()->administrator()->create();
        Sanctum::actingAs($this->administrator);
    }

    /**
     * Exactly one entry, by the signed-in administrator, from this request.
     *
     * @param  array<string, mixed>  $expected
     */
    private function assertEntryByAdministrator(AuditAction $action, AuditSubject $subject, int $subjectId, ?int $patientId, array $expected = []): AuditEntry
    {
        return $this->assertOneEntry($action, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_type' => AuditActorType::Staff,
            'actor_id' => $this->administrator->id,
            'actor_role' => 'administrator',
            'subject_type' => $subject,
            'subject_id' => $subjectId,
            'patient_id' => $patientId,
            'network_address' => '127.0.0.1',
            ...$expected,
        ]);
    }

    /* Patients (003) */

    public function test_patient_registered(): void
    {
        $id = $this->postJson('/api/v1/patients', [
            'full_name' => 'Synthetic Patient',
            'date_of_birth' => '1985-02-03',
            'sex' => 'male',
            'phone' => '01000000001',
        ])->assertSuccessful()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::PatientRegistered, AuditSubject::Patient, $id, $id);

        $this->assertContains('phone', $entry->details['fields']);
        $this->assertSame([], $entry->details['values']);
        $this->assertStringNotContainsString('01000000001', json_encode($entry->details));
    }

    public function test_patient_updated(): void
    {
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000002'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::PatientUpdated, AuditSubject::Patient, $patient->id, $patient->id);

        $this->assertSame(['phone'], $entry->details['fields']);
        $this->assertSame([], $entry->details['values']);
        $this->assertStringNotContainsString('01000000002', json_encode($entry->details));
    }

    /* Money (009) */

    private function unpaidCharge(): Charge
    {
        return Charge::factory()->create(['fee' => '300.00']);
    }

    public function test_fee_set(): void
    {
        $doctor = Doctor::factory()->create();

        $this->putJson("/api/v1/doctors/{$doctor->id}/fee", ['amount' => '350.00'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::FeeSet, AuditSubject::Doctor, $doctor->id, null);
        $this->assertEquals(['fields' => ['fee'], 'values' => ['fee' => ['from' => null, 'to' => '350.00']]], $entry->details);

        $this->putJson("/api/v1/doctors/{$doctor->id}/fee", ['amount' => '400'])->assertOk();

        $this->assertEquals(
            ['from' => '350.00', 'to' => '400.00'],
            AuditEntry::query()->where('action', AuditAction::FeeSet)->latest('id')->first()->details['values']['fee'],
        );
    }

    public function test_charge_created(): void
    {
        $entry = QueueEntry::factory()->seen()->create();
        DoctorFee::factory()->create(['doctor_id' => $entry->doctor_id, 'amount' => '350.00']);

        $id = $this->postJson("/api/v1/visits/{$entry->id}/charge")->assertCreated()->json('data.id');

        $audit = $this->assertEntryByAdministrator(AuditAction::ChargeCreated, AuditSubject::Charge, $id, $entry->patient_id);
        $this->assertEquals(['from' => null, 'to' => '350.00'], $audit->details['values']['fee']);
    }

    public function test_payment_recorded(): void
    {
        $charge = $this->unpaidCharge();

        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertSuccessful();

        $audit = $this->assertEntryByAdministrator(AuditAction::PaymentRecorded, AuditSubject::Charge, $charge->id, $charge->patient_id);
        $this->assertEquals(['from' => '0.00', 'to' => '100.00'], $audit->details['values']['amount_paid']);
        $this->assertEquals(['from' => 'unpaid', 'to' => 'partially_paid'], $audit->details['values']['status']);
    }

    public function test_refund_recorded(): void
    {
        $charge = Charge::factory()->fee('300.00')->paid()->create();

        $this->postJson("/api/v1/charges/{$charge->id}/refunds", ['amount' => '100.00', 'method' => 'cash', 'reason' => 'PLANTED-REASON'])->assertSuccessful();

        $audit = $this->assertEntryByAdministrator(AuditAction::RefundRecorded, AuditSubject::Charge, $charge->id, $charge->patient_id);
        $this->assertEquals(['from' => '300.00', 'to' => '200.00'], $audit->details['values']['amount_paid']);
        $this->assertEquals(['from' => 'paid', 'to' => 'partially_paid'], $audit->details['values']['status']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($audit->details));
    }

    public function test_discount_set(): void
    {
        $charge = $this->unpaidCharge();

        $this->putJson("/api/v1/charges/{$charge->id}/discount", ['amount' => '50.00', 'reason' => 'PLANTED-REASON'])->assertOk();

        $audit = $this->assertEntryByAdministrator(AuditAction::DiscountSet, AuditSubject::Charge, $charge->id, $charge->patient_id);
        $this->assertEqualsCanonicalizing(['discount', 'discount_reason'], $audit->details['fields']);
        $this->assertEquals(['from' => '0.00', 'to' => '50.00'], $audit->details['values']['discount']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($audit->details));
    }

    public function test_charge_cancelled(): void
    {
        $charge = $this->unpaidCharge();

        $this->postJson("/api/v1/charges/{$charge->id}/cancel", ['reason' => 'PLANTED-REASON'])->assertOk();

        $audit = $this->assertEntryByAdministrator(AuditAction::ChargeCancelled, AuditSubject::Charge, $charge->id, $charge->patient_id);
        $this->assertEquals(['from' => 'unpaid', 'to' => 'cancelled'], $audit->details['values']['status']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($audit->details));
    }

    /* Doctors (004) */

    public function test_specialty_created(): void
    {
        $id = $this->postJson('/api/v1/specialties', ['name_en' => 'Cardiology', 'name_ar' => 'أمراض القلب'])->assertCreated()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::SpecialtyCreated, AuditSubject::Specialty, $id, null);
        $this->assertContains('name_en', $entry->details['fields']);
        $this->assertEquals(['from' => null, 'to' => true], $entry->details['values']['is_active']);
    }

    public function test_specialty_updated(): void
    {
        $specialty = Specialty::factory()->create();

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'Synthetic Renamed'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::SpecialtyUpdated, AuditSubject::Specialty, $specialty->id, null);
        $this->assertSame(['name_en'], $entry->details['fields']);
    }

    public function test_specialty_deactivated(): void
    {
        $specialty = Specialty::factory()->create();

        $this->postJson("/api/v1/specialties/{$specialty->id}/deactivate")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::SpecialtyDeactivated, AuditSubject::Specialty, $specialty->id, null);
        $this->assertEquals(['from' => true, 'to' => false], $entry->details['values']['is_active']);
    }

    public function test_specialty_reactivated(): void
    {
        $specialty = Specialty::factory()->create(['is_active' => false]);

        $this->postJson("/api/v1/specialties/{$specialty->id}/reactivate")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::SpecialtyReactivated, AuditSubject::Specialty, $specialty->id, null);
        $this->assertEquals(['from' => false, 'to' => true], $entry->details['values']['is_active']);
    }

    public function test_doctor_created(): void
    {
        $id = $this->postJson('/api/v1/doctors', [
            'user_id' => User::factory()->doctor()->create()->id,
            'title' => 'Consultant',
            'specialty_ids' => [Specialty::factory()->create()->id],
        ])->assertCreated()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::DoctorCreated, AuditSubject::Doctor, $id, null);
        $this->assertContains('title', $entry->details['fields']);
        $this->assertContains('specialty_ids', $entry->details['fields']);
    }

    public function test_doctor_updated(): void
    {
        $doctor = Doctor::factory()->create();

        $this->patchJson("/api/v1/doctors/{$doctor->id}", ['specialty_ids' => [Specialty::factory()->create()->id]])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::DoctorUpdated, AuditSubject::Doctor, $doctor->id, null);
        $this->assertSame(['specialty_ids'], $entry->details['fields']);
    }

    /* Schedules (005) */

    public function test_schedule_set(): void
    {
        $doctor = Doctor::factory()->create();

        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", [
            'appointment_minutes' => 20,
            'periods' => [['day' => 'monday', 'start' => '09:00', 'end' => '12:00']],
        ])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::ScheduleSet, AuditSubject::Doctor, $doctor->id, null);
        $this->assertContains('appointment_minutes', $entry->details['fields']);
        $this->assertContains('periods', $entry->details['fields']);
    }

    public function test_schedule_set_for_the_first_time_with_no_periods_still_writes_an_entry(): void
    {
        $doctor = Doctor::factory()->create();

        $this->putJson("/api/v1/doctors/{$doctor->id}/schedule", ['appointment_minutes' => 20, 'periods' => []])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::ScheduleSet, AuditSubject::Doctor, $doctor->id, null);
        $this->assertContains('appointment_minutes', $entry->details['fields']);
        $this->assertNotContains('periods', $entry->details['fields']);
    }

    public function test_schedule_cleared(): void
    {
        $schedule = DoctorSchedule::factory()->withPeriod('monday', '09:00', '12:00')->create();

        $this->deleteJson("/api/v1/doctors/{$schedule->doctor_id}/schedule")->assertSuccessful();

        $this->assertEntryByAdministrator(AuditAction::ScheduleCleared, AuditSubject::Doctor, $schedule->doctor_id, null);
    }

    public function test_time_off_added(): void
    {
        $doctor = Doctor::factory()->create();

        $id = $this->postJson("/api/v1/doctors/{$doctor->id}/time-off", [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => 'PLANTED-REASON',
        ])->assertCreated()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::TimeOffAdded, AuditSubject::TimeOff, $id, null);
        $this->assertContains('reason', $entry->details['fields']);
        $this->assertSame([], $entry->details['values']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($entry->details));
    }

    public function test_time_off_removed(): void
    {
        $timeOff = DoctorTimeOff::factory()->create();

        $this->deleteJson("/api/v1/doctors/{$timeOff->doctor_id}/time-off/{$timeOff->id}")->assertSuccessful();

        $entry = $this->assertEntryByAdministrator(AuditAction::TimeOffRemoved, AuditSubject::TimeOff, $timeOff->id, null);

        $this->getJson("/api/v1/audit-entries?subject_type=time_off&subject_id={$timeOff->id}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $entry->id);
    }

    /* Appointments (006) */

    private function doctorWithSchedule(): Doctor
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()
            ->withPeriod('sunday', '09:00', '10:00')
            ->withPeriod('tuesday', '10:00', '11:00')
            ->create(['doctor_id' => $doctor->id]);

        return $doctor;
    }

    private function bookedAppointment(string $start = '09:20'): Appointment
    {
        return Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $this->doctorWithSchedule()->id]);
    }

    public function test_appointment_booked(): void
    {
        $patient = Patient::factory()->create();
        $doctor = $this->doctorWithSchedule();

        $id = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
            'note' => 'PLANTED-NOTE',
        ])->assertCreated()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::AppointmentBooked, AuditSubject::Appointment, $id, $patient->id);
        $this->assertEquals(['from' => null, 'to' => '2026-11-01'], $entry->details['values']['date']);
        $this->assertEquals(['from' => null, 'to' => $doctor->id], $entry->details['values']['doctor_id']);
        $this->assertContains('note', $entry->details['fields']);
        $this->assertStringNotContainsString('PLANTED-NOTE', json_encode($entry->details));
    }

    public function test_appointment_rescheduled(): void
    {
        $appointment = $this->bookedAppointment();

        $this->postJson("/api/v1/appointments/{$appointment->id}/reschedule", ['date' => '2026-11-03', 'start' => '10:00'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::AppointmentRescheduled, AuditSubject::Appointment, $appointment->id, $appointment->patient_id);
        $this->assertEquals(['from' => '2026-11-01', 'to' => '2026-11-03'], $entry->details['values']['date']);
        $this->assertEquals(['from' => 560, 'to' => 600], $entry->details['values']['start_minute']);
        $this->assertArrayHasKey('end_minute', $entry->details['values']);
    }

    public function test_appointment_cancelled(): void
    {
        $appointment = $this->bookedAppointment();

        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel", ['reason' => 'PLANTED-REASON'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::AppointmentCancelled, AuditSubject::Appointment, $appointment->id, $appointment->patient_id);
        $this->assertEquals(['from' => 'scheduled', 'to' => 'cancelled'], $entry->details['values']['status']);
        $this->assertContains('cancellation_reason', $entry->details['fields']);
        $this->assertStringNotContainsString('PLANTED-REASON', json_encode($entry->details));
    }

    public function test_appointment_no_show(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '07:00')->create();

        $this->postJson("/api/v1/appointments/{$appointment->id}/no-show")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::AppointmentNoShow, AuditSubject::Appointment, $appointment->id, $appointment->patient_id);
        $this->assertEquals(['from' => 'scheduled', 'to' => 'no_show'], $entry->details['values']['status']);
    }

    /* Visits (007) */

    public function test_visit_checked_in(): void
    {
        $appointment = $this->bookedAppointment();

        $id = $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated()->json('data.id');

        // One entry, though the appointment's status changes too (spec, Edge Cases).
        $entry = $this->assertEntryByAdministrator(AuditAction::VisitCheckedIn, AuditSubject::Visit, $id, $appointment->patient_id);
        $this->assertEquals(['from' => null, 'to' => 'waiting'], $entry->details['values']['status']);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    public function test_visit_walk_in_added(): void
    {
        $patient = Patient::factory()->create();

        $id = $this->postJson('/api/v1/queue/walk-ins', [
            'patient_id' => $patient->id,
            'doctor_id' => Doctor::factory()->create()->id,
        ])->assertCreated()->json('data.id');

        $this->assertEntryByAdministrator(AuditAction::VisitWalkInAdded, AuditSubject::Visit, $id, $patient->id);
    }

    public function test_visit_left(): void
    {
        $entry = QueueEntry::factory()->create();

        $this->postJson("/api/v1/queue/{$entry->id}/leave")->assertOk();

        $audit = $this->assertEntryByAdministrator(AuditAction::VisitLeft, AuditSubject::Visit, $entry->id, $entry->patient_id);
        $this->assertEquals(['from' => 'waiting', 'to' => 'left'], $audit->details['values']['status']);
    }

    public function test_visit_check_in_undone(): void
    {
        $appointment = $this->bookedAppointment();
        $id = $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated()->json('data.id');

        $this->deleteJson("/api/v1/queue/{$id}")->assertSuccessful();

        $this->assertNull(QueueEntry::query()->find($id));

        // The entry survives, although its record is gone.
        $this->assertEntryByAdministrator(AuditAction::VisitCheckInUndone, AuditSubject::Visit, $id, $appointment->patient_id);
    }

    private function signInAsDoctor(): Doctor
    {
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs($doctor->user);

        return $doctor;
    }

    public function test_visit_called(): void
    {
        $doctor = $this->signInAsDoctor();
        $entry = QueueEntry::factory()->create(['doctor_id' => $doctor->id]);

        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertOk();

        $audit = $this->assertOneEntry(AuditAction::VisitCalled, [
            'actor_id' => $doctor->user_id,
            'actor_role' => 'doctor',
            'subject_type' => AuditSubject::Visit,
            'subject_id' => $entry->id,
            'patient_id' => $entry->patient_id,
        ]);
        $this->assertEquals(['from' => 'waiting', 'to' => 'with_doctor'], $audit->details['values']['status']);
    }

    public function test_visit_finished(): void
    {
        $doctor = $this->signInAsDoctor();
        $appointment = Appointment::factory()->at('2026-11-01', '07:00')->create(['doctor_id' => $doctor->id, 'status' => AppointmentStatus::CheckedIn]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->withDoctor()->create();

        $this->postJson('/api/v1/doctors/me/queue/finish')->assertOk();

        // One entry, though the appointment's status changes too (spec, Edge Cases).
        $audit = $this->assertOneEntry(AuditAction::VisitFinished, [
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Visit,
            'subject_id' => $entry->id,
            'patient_id' => $entry->patient_id,
        ]);
        $this->assertEquals(['from' => 'with_doctor', 'to' => 'seen'], $audit->details['values']['status']);
        $this->assertSame(1, AuditEntry::query()->count());
    }

    /* Medical records (008) */

    /**
     * @return array<string, mixed>
     */
    private function encounterContent(string $text): array
    {
        return [
            'chief_complaint' => $text,
            'examination_findings' => $text,
            'diagnosis' => $text,
            'treatment_plan' => $text,
            'prescriptions' => [['medication' => $text]],
        ];
    }

    public function test_encounter_started(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id]);

        $id = $this->postJson("/api/v1/doctors/me/visits/{$visit->id}/encounter")->assertCreated()->json('data.id');

        $entry = $this->assertOneEntry(AuditAction::EncounterStarted, [
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $id,
            'patient_id' => $visit->patient_id,
        ]);
        $this->assertEquals(['from' => null, 'to' => 'draft'], $entry->details['values']['status']);
    }

    public function test_encounter_draft_saved(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id]);
        $encounter = Encounter::factory()->forEntry($visit)->create();

        $this->putJson("/api/v1/doctors/me/encounters/{$encounter->id}", $this->encounterContent('PLANTED-CLINICAL'))->assertOk();

        $entry = $this->assertOneEntry(AuditAction::EncounterDraftSaved, [
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $encounter->id,
            'patient_id' => $visit->patient_id,
        ]);
        $this->assertEqualsCanonicalizing(['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions'], $entry->details['fields']);
        $this->assertSame([], $entry->details['values']);

        $json = json_encode($entry->details);
        $this->assertStringNotContainsString('PLANTED-CLINICAL', $json);
        $this->assertStringNotContainsString($encounter->fresh()->getRawOriginal('diagnosis'), $json);
    }

    public function test_encounter_finalized(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id]);
        $encounter = Encounter::factory()->forEntry($visit)->complete()->create();

        $this->postJson("/api/v1/doctors/me/encounters/{$encounter->id}/finalize")->assertOk();

        $entry = $this->assertOneEntry(AuditAction::EncounterFinalized, [
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $encounter->id,
            'patient_id' => $visit->patient_id,
        ]);
        $this->assertEquals(['from' => 'draft', 'to' => 'finalized'], $entry->details['values']['status']);
    }

    public function test_encounter_addendum_added(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]);
        $encounter = Encounter::factory()->forEntry($visit)->finalized()->create();

        $this->postJson("/api/v1/doctors/me/encounters/{$encounter->id}/addenda", ['text' => 'PLANTED-CLINICAL'])->assertCreated();

        $entry = $this->assertOneEntry(AuditAction::EncounterAddendumAdded, [
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $encounter->id,
            'patient_id' => $visit->patient_id,
        ]);
        $this->assertSame(['fields' => ['text']], $entry->details);
        $this->assertStringNotContainsString('PLANTED-CLINICAL', json_encode($entry->details));
    }

    /* Openings (US2) */

    public function test_patient_opened(): void
    {
        $patient = Patient::factory()->create();

        $this->getJson("/api/v1/patients/{$patient->id}")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::PatientOpened, AuditSubject::Patient, $patient->id, $patient->id);
        $this->assertNull($entry->details);
    }

    public function test_encounter_opened(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]);
        $encounter = Encounter::factory()->forEntry($visit)->create();

        $this->getJson("/api/v1/doctors/me/encounters/{$encounter->id}")->assertOk();

        $this->assertOneEntry(AuditAction::EncounterOpened, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Encounter,
            'subject_id' => $encounter->id,
            'patient_id' => $visit->patient_id,
        ]);
    }

    public function test_encounter_history_viewed(): void
    {
        $doctor = $this->signInAsDoctor();
        $visit = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]);
        Encounter::factory()->forEntry($visit)->finalized()->create();

        $this->getJson("/api/v1/doctors/me/patients/{$visit->patient_id}/encounters")->assertOk();

        $this->assertOneEntry(AuditAction::EncounterHistoryViewed, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_id' => $doctor->user_id,
            'subject_type' => AuditSubject::Patient,
            'subject_id' => $visit->patient_id,
            'patient_id' => $visit->patient_id,
        ]);
    }

    /* Accounts (002) */

    public function test_auth_sign_in(): void
    {
        $user = User::factory()->doctor()->create();

        $this->postJson('/api/v1/auth/sign-in', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk();

        // The actor is the one signing in, passed explicitly because nobody is authenticated yet.
        $this->assertOneEntry(AuditAction::AuthSignIn, [
            'outcome' => AuditOutcome::Succeeded,
            'actor_type' => AuditActorType::Staff,
            'actor_id' => $user->id,
            'actor_role' => 'doctor',
            'subject_type' => AuditSubject::StaffMember,
            'subject_id' => $user->id,
            'network_address' => '127.0.0.1',
        ]);
    }

    public function test_auth_sign_out(): void
    {
        $user = User::factory()->doctor()->create();
        $token = $this->postJson('/api/v1/auth/sign-in', ['email' => $user->email, 'password' => UserFactory::PASSWORD])->assertOk()->json('data.token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->postJson('/api/v1/auth/sign-out')->assertNoContent();

        $this->assertOneEntry(AuditAction::AuthSignOut, [
            'actor_id' => $user->id,
            'subject_type' => AuditSubject::StaffMember,
            'subject_id' => $user->id,
        ]);
    }

    public function test_auth_password_changed(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/auth/password', [
            'current_password' => UserFactory::PASSWORD,
            'password' => 'planted-new-pass-1',
            'password_confirmation' => 'planted-new-pass-1',
        ])->assertSuccessful();

        $entry = $this->assertOneEntry(AuditAction::AuthPasswordChanged, ['actor_id' => $user->id, 'subject_id' => $user->id]);
        $this->assertSame(['fields' => ['password']], $entry->details);
    }

    public function test_staff_created(): void
    {
        $id = $this->postJson('/api/v1/staff', [
            'name' => 'Synthetic Reception',
            'email' => 'reception@clinic.test',
            'role' => 'receptionist',
            'password' => 'planted-new-pass-1',
            'password_confirmation' => 'planted-new-pass-1',
        ])->assertCreated()->json('data.id');

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffCreated, AuditSubject::StaffMember, $id, null);
        $this->assertContains('password', $entry->details['fields']);
        $this->assertArrayNotHasKey('password', $entry->details['values']);
        $this->assertEquals(['from' => null, 'to' => 'receptionist'], $entry->details['values']['role']);
        $this->assertEquals(['from' => null, 'to' => true], $entry->details['values']['is_active']);

        $stored = json_encode($entry->getAttributes());
        $this->assertStringNotContainsString('planted-new-pass-1', $stored);
        $this->assertStringNotContainsString('reception@clinic.test', $stored);
        $this->assertStringNotContainsString('Synthetic Reception', $stored);
    }

    public function test_staff_updated(): void
    {
        $member = User::factory()->receptionist()->create();

        $this->patchJson("/api/v1/staff/{$member->id}", ['role' => 'doctor', 'name' => 'Planted Name'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffUpdated, AuditSubject::StaffMember, $member->id, null);
        $this->assertEqualsCanonicalizing(['role', 'name'], $entry->details['fields']);
        $this->assertEquals(['from' => 'receptionist', 'to' => 'doctor'], $entry->details['values']['role']);
        $this->assertStringNotContainsString('Planted Name', json_encode($entry->details));
    }

    public function test_staff_updated_when_demoting_an_administrator(): void
    {
        $other = User::factory()->administrator()->create();

        $this->patchJson("/api/v1/staff/{$other->id}", ['role' => 'receptionist'])->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffUpdated, AuditSubject::StaffMember, $other->id, null);
        $this->assertEquals(['from' => 'administrator', 'to' => 'receptionist'], $entry->details['values']['role']);
    }

    public function test_staff_deactivated(): void
    {
        $member = User::factory()->receptionist()->create();

        $this->postJson("/api/v1/staff/{$member->id}/deactivate")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffDeactivated, AuditSubject::StaffMember, $member->id, null);
        $this->assertEquals(['from' => true, 'to' => false], $entry->details['values']['is_active']);
    }

    public function test_staff_reactivated(): void
    {
        $member = User::factory()->inactive()->create();

        $this->postJson("/api/v1/staff/{$member->id}/reactivate")->assertOk();

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffReactivated, AuditSubject::StaffMember, $member->id, null);
        $this->assertEquals(['from' => false, 'to' => true], $entry->details['values']['is_active']);
    }

    public function test_staff_password_reset(): void
    {
        $member = User::factory()->create();

        $this->putJson("/api/v1/staff/{$member->id}/password", [
            'password' => 'planted-new-pass-1',
            'password_confirmation' => 'planted-new-pass-1',
        ])->assertSuccessful();

        $entry = $this->assertEntryByAdministrator(AuditAction::StaffPasswordReset, AuditSubject::StaffMember, $member->id, null);
        $this->assertSame(['fields' => ['password']], $entry->details);
    }
}
