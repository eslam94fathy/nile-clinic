<?php

namespace Tests\Feature\Audit;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Doctors\Models\Specialty;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Models\EncounterAddendum;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Models\Payment;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * SC-004, FR-007: a change and its entry stand or fall together. A failed entry leaves the record
 * unchanged, and a refused change leaves no entry.
 */
class AuditAtomicityTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    /* Patients (003) */

    public function test_registering_leaves_no_patient_when_the_entry_fails(): void
    {
        $this->failAuditWrites();

        $this->postJson('/api/v1/patients', [
            'full_name' => 'Synthetic Patient',
            'date_of_birth' => '1985-02-03',
            'sex' => 'male',
            'phone' => '01000000001',
        ])->assertStatus(500);

        $this->assertSame(0, Patient::query()->count());
    }

    public function test_updating_leaves_the_patient_unchanged_when_the_entry_fails(): void
    {
        $patient = Patient::factory()->create(['phone' => '01000000001']);
        $this->failAuditWrites();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['phone' => '01000000002'])->assertStatus(500);

        $this->assertSame('01000000001', $patient->fresh()->phone);
    }

    public function test_an_invalid_update_leaves_no_entry(): void
    {
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['date_of_birth' => 'not-a-date'])->assertUnprocessable();

        $this->assertNoEntries(AuditAction::PatientUpdated);
    }

    public function test_a_duplicate_national_id_is_still_a_validation_error_and_leaves_no_entry(): void
    {
        Patient::factory()->create(['national_id' => '29001010101010']);
        $patient = Patient::factory()->create();

        $this->patchJson("/api/v1/patients/{$patient->id}", ['national_id' => '29001010101010'])->assertUnprocessable();

        $this->assertSame(0, AuditEntry::query()->count());
    }

    /* Money (009) */

    public function test_a_payment_is_not_kept_when_the_entry_fails(): void
    {
        $charge = Charge::factory()->create(['fee' => '300.00']);
        $this->failAuditWrites();

        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertStatus(500);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame('0.00', $charge->fresh()->amount_paid);
    }

    public function test_a_discount_is_not_kept_when_the_entry_fails(): void
    {
        $charge = Charge::factory()->create(['fee' => '300.00']);
        $this->failAuditWrites();

        $this->putJson("/api/v1/charges/{$charge->id}/discount", ['amount' => '50.00', 'reason' => 'Synthetic'])->assertStatus(500);

        $this->assertSame('0.00', $charge->fresh()->discount);
    }

    public function test_a_cancellation_is_not_kept_when_the_entry_fails(): void
    {
        $charge = Charge::factory()->create();
        $this->failAuditWrites();

        $this->postJson("/api/v1/charges/{$charge->id}/cancel", ['reason' => 'Synthetic'])->assertStatus(500);

        $this->assertSame(ChargeStatus::Unpaid, $charge->fresh()->status);
    }

    public function test_a_fee_is_not_kept_when_the_entry_fails(): void
    {
        $fee = DoctorFee::factory()->create(['doctor_id' => Doctor::factory(), 'amount' => '300.00']);
        $this->failAuditWrites();

        $this->putJson("/api/v1/doctors/{$fee->doctor_id}/fee", ['amount' => '400.00'])->assertStatus(500);

        $this->assertSame('300.00', $fee->fresh()->amount);
    }

    public function test_a_payment_above_the_balance_leaves_no_entry(): void
    {
        $charge = Charge::factory()->create(['fee' => '300.00']);

        $this->postJson("/api/v1/charges/{$charge->id}/payments", ['amount' => '999.00', 'method' => 'cash'])->assertUnprocessable();

        $this->assertNoEntries(AuditAction::PaymentRecorded);
    }

    /* Doctors (004) */

    public function test_renaming_a_specialty_is_not_kept_when_the_entry_fails(): void
    {
        $specialty = Specialty::factory()->create(['name_en' => 'Before']);
        $this->failAuditWrites();

        $this->patchJson("/api/v1/specialties/{$specialty->id}", ['name_en' => 'After'])->assertStatus(500);

        $this->assertSame('Before', $specialty->fresh()->name_en);
    }

    public function test_deactivating_a_specialty_is_not_kept_when_the_entry_fails(): void
    {
        $specialty = Specialty::factory()->create();
        $this->failAuditWrites();

        $this->postJson("/api/v1/specialties/{$specialty->id}/deactivate")->assertStatus(500);

        $this->assertTrue($specialty->fresh()->is_active);
    }

    public function test_updating_a_doctor_is_not_kept_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->withSpecialties()->create(['title' => 'Before']);
        $before = $doctor->specialties()->pluck('specialties.id')->all();
        $this->failAuditWrites();

        $this->patchJson("/api/v1/doctors/{$doctor->id}", [
            'title' => 'After',
            'specialty_ids' => [Specialty::factory()->create()->id],
        ])->assertStatus(500);

        $this->assertSame('Before', $doctor->fresh()->title);
        $this->assertSame($before, $doctor->specialties()->pluck('specialties.id')->all());
    }

    /* Schedules (005) */

    public function test_replacing_a_schedule_is_not_kept_when_the_entry_fails(): void
    {
        $schedule = DoctorSchedule::factory()->withPeriod('monday', '09:00', '12:00')->create();
        $this->failAuditWrites();

        $this->putJson("/api/v1/doctors/{$schedule->doctor_id}/schedule", [
            'appointment_minutes' => 30,
            'periods' => [['day' => 'tuesday', 'start' => '10:00', 'end' => '11:00']],
        ])->assertStatus(500);

        $fresh = $schedule->fresh();
        $this->assertSame(20, $fresh->appointment_minutes);
        $this->assertSame(540, $fresh->periods()->sole()->start_minute);
    }

    public function test_clearing_a_schedule_is_not_kept_when_the_entry_fails(): void
    {
        $schedule = DoctorSchedule::factory()->create();
        $this->failAuditWrites();

        $this->deleteJson("/api/v1/doctors/{$schedule->doctor_id}/schedule")->assertStatus(500);

        $this->assertNotNull($schedule->fresh());
    }

    public function test_adding_time_off_is_not_kept_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->create();
        $this->failAuditWrites();

        $this->postJson("/api/v1/doctors/{$doctor->id}/time-off", ['start_date' => '2026-11-10', 'end_date' => '2026-11-11'])->assertStatus(500);

        $this->assertSame(0, DoctorTimeOff::query()->count());
    }

    public function test_removing_time_off_is_not_kept_when_the_entry_fails(): void
    {
        $timeOff = DoctorTimeOff::factory()->create();
        $this->failAuditWrites();

        $this->deleteJson("/api/v1/doctors/{$timeOff->doctor_id}/time-off/{$timeOff->id}")->assertStatus(500);

        $this->assertNotNull($timeOff->fresh());
    }

    /* Appointments and visits (006, 007) */

    private function appointmentToday(): Appointment
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $doctor->id]);

        return Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $doctor->id]);
    }

    public function test_booking_leaves_no_appointment_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $doctor->id]);
        $this->failAuditWrites();

        $this->postJson('/api/v1/appointments', [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ])->assertStatus(500);

        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_a_cancellation_of_an_appointment_is_not_kept_when_the_entry_fails(): void
    {
        $appointment = $this->appointmentToday();
        $this->failAuditWrites();

        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel", ['reason' => 'Synthetic'])->assertStatus(500);

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_a_check_in_leaves_no_visit_when_the_entry_fails(): void
    {
        $appointment = $this->appointmentToday();
        $this->failAuditWrites();

        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertStatus(500);

        $this->assertSame(0, QueueEntry::query()->count());
        $this->assertSame(AppointmentStatus::Scheduled, $appointment->fresh()->status);
    }

    public function test_undoing_a_check_in_keeps_the_visit_when_the_entry_fails(): void
    {
        $appointment = $this->appointmentToday();
        $appointment->update(['status' => AppointmentStatus::CheckedIn]);
        $entry = QueueEntry::factory()->forAppointment($appointment)->create();
        $this->failAuditWrites();

        $this->deleteJson("/api/v1/queue/{$entry->id}")->assertStatus(500);

        $this->assertNotNull($entry->fresh());
        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->fresh()->status);
    }

    public function test_calling_next_leaves_the_patient_waiting_when_the_entry_fails(): void
    {
        $doctor = Doctor::factory()->create();
        $entry = QueueEntry::factory()->create(['doctor_id' => $doctor->id]);
        Sanctum::actingAs($doctor->user);
        $this->failAuditWrites();

        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertStatus(500);

        $this->assertSame(QueueStatus::Waiting, $entry->fresh()->status);
    }

    /* Medical records (008) */

    /**
     * @return array{Doctor, QueueEntry}
     */
    private function doctorWithVisit(): array
    {
        $doctor = Doctor::factory()->create();
        Sanctum::actingAs($doctor->user);

        return [$doctor, QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id])];
    }

    public function test_saving_a_draft_is_not_kept_when_the_entry_fails(): void
    {
        [, $visit] = $this->doctorWithVisit();
        $encounter = Encounter::factory()->forEntry($visit)->create(['diagnosis' => 'Before']);
        $this->failAuditWrites();

        $this->putJson("/api/v1/doctors/me/encounters/{$encounter->id}", [
            'chief_complaint' => 'After',
            'examination_findings' => 'After',
            'diagnosis' => 'After',
            'treatment_plan' => 'After',
            'prescriptions' => [],
        ])->assertStatus(500);

        $this->assertSame('Before', $encounter->fresh()->diagnosis);
    }

    public function test_finalizing_leaves_a_draft_when_the_entry_fails(): void
    {
        [, $visit] = $this->doctorWithVisit();
        $encounter = Encounter::factory()->forEntry($visit)->complete()->create();
        $this->failAuditWrites();

        $this->postJson("/api/v1/doctors/me/encounters/{$encounter->id}/finalize")->assertStatus(500);

        $this->assertSame(EncounterStatus::Draft, $encounter->fresh()->status);
    }

    public function test_an_addendum_is_not_kept_when_the_entry_fails(): void
    {
        [, $visit] = $this->doctorWithVisit();
        $encounter = Encounter::factory()->forEntry($visit)->finalized()->create();
        $this->failAuditWrites();

        $this->postJson("/api/v1/doctors/me/encounters/{$encounter->id}/addenda", ['text' => 'Synthetic'])->assertStatus(500);

        $this->assertSame(0, EncounterAddendum::query()->count());
    }
}
