<?php

namespace Tests\Feature\Audit;

use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\Audit\Concerns\InteractsWithAuditEntries;
use Tests\TestCase;

/**
 * SC-003, FR-006, FR-008, FR-012, FR-017: planted patient details, clinical text, reasons, passwords, and
 * search text reach no entry, no search response, and no log, through real requests.
 */
class AuditPrivacyTest extends TestCase
{
    use InteractsWithAuditEntries, RefreshDatabase;

    private const PLANTED = [
        'name' => 'Plantedname Syntheticfamily',
        'phone' => '01099887766',
        'national_id' => '29901019988776',
        'address' => 'PLANTED-ADDRESS',
        'contact_name' => 'PLANTED-CONTACT',
        'contact_phone' => '01099887755',
        'note' => 'PLANTED-NOTE',
        'cancellation' => 'PLANTED-CANCELLATION',
        'discount' => 'PLANTED-DISCOUNT',
        'refund' => 'PLANTED-REFUND',
        'charge_cancel' => 'PLANTED-CHARGE-CANCEL',
        'time_off' => 'PLANTED-TIME-OFF',
        'complaint' => 'PLANTED-COMPLAINT',
        'findings' => 'PLANTED-FINDINGS',
        'diagnosis' => 'PLANTED-DIAGNOSIS',
        'plan' => 'PLANTED-PLAN',
        'medication' => 'PLANTED-MEDICATION',
        'addendum' => 'PLANTED-ADDENDUM',
        'password' => 'planted-password-1',
        'wrong_password' => 'planted-wrong-password-1',
        'unknown_email' => 'planted-unknown@clinic.test',
        'search' => 'PLANTEDSEARCHTEXT',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
    }

    public function test_no_planted_value_reaches_an_entry_or_a_search_response(): void
    {
        $encounterId = $this->driveEveryKindOfAction();

        // Every column of every row, as stored.
        $stored = json_encode(DB::table('audit_entries')->get());
        $this->assertGreaterThan(15, AuditEntry::query()->count());

        foreach (self::PLANTED as $key => $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $stored, "{$key} reached an entry.");
        }

        $this->assertStringNotContainsString(Encounter::query()->findOrFail($encounterId)->getRawOriginal('diagnosis'), $stored);

        Sanctum::actingAs(User::factory()->administrator()->create());
        $searched = $this->getJson('/api/v1/audit-entries?per_page=100')->assertOk()->getContent();

        foreach (self::PLANTED as $key => $value) {
            $this->assertStringNotContainsStringIgnoringCase($value, $searched, "{$key} reached a search response.");
        }
    }

    public function test_no_planted_value_reaches_the_log_when_recording_fails(): void
    {
        $patient = Patient::factory()->create();
        $record = Encounter::factory()
            ->forEntry(QueueEntry::factory()->seen()->create(['patient_id' => $patient->id, 'date' => '2026-10-15']))
            ->finalized()
            ->create(['diagnosis' => self::PLANTED['diagnosis']]);

        $logFile = storage_path('logs/audit-privacy-test.log');
        File::delete($logFile);
        config(['logging.channels.audit_privacy_test' => ['driver' => 'single', 'path' => $logFile]]);
        Log::setDefaultDriver('audit_privacy_test');

        $this->failAuditWrites();

        try {
            // A change, which fails.
            Sanctum::actingAs(User::factory()->receptionist()->create());
            $this->patchJson("/api/v1/patients/{$patient->id}", [
                'full_name' => self::PLANTED['name'],
                'phone' => self::PLANTED['phone'],
                'address' => self::PLANTED['address'],
            ])->assertServerError();

            // A refused opening, which keeps its response.
            $this->getJson("/api/v1/doctors/me/encounters/{$record->id}")->assertForbidden();

            // A failed sign-in, which keeps its response.
            $this->postJson('/api/v1/auth/sign-in', [
                'email' => self::PLANTED['unknown_email'],
                'password' => self::PLANTED['wrong_password'],
            ])->assertUnprocessable();

            $log = File::get($logFile);

            $this->assertStringContainsString('Forced audit failure', $log);

            foreach (self::PLANTED as $key => $value) {
                $this->assertStringNotContainsStringIgnoringCase($value, $log, "{$key} reached the log.");
            }
        } finally {
            File::delete($logFile);
        }
    }

    /**
     * Through the API, plant a value in every free-text field and credential that can reach a recorded
     * action. Returns the medical record's id.
     */
    private function driveEveryKindOfAction(): int
    {
        $administrator = User::factory()->administrator()->create();
        $doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $doctor->id, 'amount' => '300.00']);
        $bookable = Doctor::factory()->create();
        Sanctum::actingAs($administrator);

        $patientId = $this->postJson('/api/v1/patients', [
            'full_name' => self::PLANTED['name'],
            'date_of_birth' => '1985-02-03',
            'sex' => 'male',
            'phone' => self::PLANTED['phone'],
            'national_id' => self::PLANTED['national_id'],
            'address' => self::PLANTED['address'],
            'emergency_contact' => ['name' => self::PLANTED['contact_name'], 'relationship' => 'Sibling', 'phone' => self::PLANTED['contact_phone']],
        ])->assertSuccessful()->json('data.id');

        $this->getJson("/api/v1/patients/{$patientId}")->assertOk();
        $this->getJson('/api/v1/patients?name='.self::PLANTED['search'])->assertOk();
        $this->patchJson("/api/v1/patients/{$patientId}", ['address' => self::PLANTED['address'].'-2'])->assertOk();

        $this->putJson("/api/v1/doctors/{$bookable->id}/schedule", [
            'appointment_minutes' => 20,
            'periods' => [['day' => 'sunday', 'start' => '09:00', 'end' => '12:00']],
        ])->assertOk();
        $this->postJson("/api/v1/doctors/{$bookable->id}/time-off", [
            'start_date' => '2026-11-10',
            'end_date' => '2026-11-11',
            'reason' => self::PLANTED['time_off'],
        ])->assertCreated();

        $appointment = $this->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $bookable->id,
            'date' => '2026-11-01',
            'start' => '09:20',
            'note' => self::PLANTED['note'],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/appointments/{$appointment}/cancel", ['reason' => self::PLANTED['cancellation']])->assertOk();

        $visit = $this->postJson('/api/v1/queue/walk-ins', ['patient_id' => $patientId, 'doctor_id' => $doctor->id])->assertCreated()->json('data.id');
        $charge = $this->postJson("/api/v1/visits/{$visit}/charge")->assertCreated()->json('data.id');
        $this->putJson("/api/v1/charges/{$charge}/discount", ['amount' => '50.00', 'reason' => self::PLANTED['discount']])->assertOk();
        $this->postJson("/api/v1/charges/{$charge}/payments", ['amount' => '100.00', 'method' => 'cash'])->assertSuccessful();
        $this->postJson("/api/v1/charges/{$charge}/refunds", ['amount' => '100.00', 'method' => 'cash', 'reason' => self::PLANTED['refund']])->assertSuccessful();
        $this->postJson("/api/v1/charges/{$charge}/cancel", ['reason' => self::PLANTED['charge_cancel']])->assertOk();

        $member = $this->postJson('/api/v1/staff', [
            'name' => 'Synthetic Member',
            'email' => 'member@clinic.test',
            'role' => 'receptionist',
            'password' => self::PLANTED['password'],
            'password_confirmation' => self::PLANTED['password'],
        ])->assertCreated()->json('data.id');
        $this->putJson("/api/v1/staff/{$member}/password", [
            'password' => self::PLANTED['password'].'2',
            'password_confirmation' => self::PLANTED['password'].'2',
        ])->assertSuccessful();

        Sanctum::actingAs($doctor->user);
        $this->postJson('/api/v1/doctors/me/queue/call-next')->assertOk();
        $encounter = $this->postJson("/api/v1/doctors/me/visits/{$visit}/encounter")->assertCreated()->json('data.id');
        $this->putJson("/api/v1/doctors/me/encounters/{$encounter}", [
            'chief_complaint' => self::PLANTED['complaint'],
            'examination_findings' => self::PLANTED['findings'],
            'diagnosis' => self::PLANTED['diagnosis'],
            'treatment_plan' => self::PLANTED['plan'],
            'prescriptions' => [[
                'medication' => self::PLANTED['medication'],
                'dosage' => self::PLANTED['medication'],
                'frequency' => self::PLANTED['medication'],
                'duration' => self::PLANTED['medication'],
            ]],
        ])->assertOk();
        $this->postJson("/api/v1/doctors/me/encounters/{$encounter}/finalize")->assertOk();
        $this->postJson("/api/v1/doctors/me/encounters/{$encounter}/addenda", ['text' => self::PLANTED['addendum']])->assertCreated();
        $this->getJson("/api/v1/doctors/me/encounters/{$encounter}")->assertOk()->assertSee(self::PLANTED['addendum']);
        $this->getJson("/api/v1/doctors/me/patients/{$patientId}/encounters")->assertOk();

        $this->app['auth']->forgetGuards();

        $this->postJson('/api/v1/auth/sign-in', ['email' => 'member@clinic.test', 'password' => self::PLANTED['wrong_password']])->assertUnprocessable();
        $this->postJson('/api/v1/auth/sign-in', ['email' => self::PLANTED['unknown_email'], 'password' => self::PLANTED['wrong_password']])->assertUnprocessable();

        $signingIn = User::factory()->create();
        $this->postJson('/api/v1/auth/sign-in', ['email' => $signingIn->email, 'password' => UserFactory::PASSWORD])->assertOk();

        return $encounter;
    }
}
