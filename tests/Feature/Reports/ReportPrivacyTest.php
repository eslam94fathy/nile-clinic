<?php

namespace Tests\Feature\Reports;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * No report identifies a patient, holds a reason, or reads clinical content (SC-005, FR-004, FR-017,
 * research R11). Values are planted where they would show if any report selected them.
 */
class ReportPrivacyTest extends TestCase
{
    use RefreshDatabase;

    private const PATHS = [
        '/api/v1/reports/money-collected',
        '/api/v1/reports/billing',
        '/api/v1/reports/activity',
        '/api/v1/reports/times',
    ];

    private const PERIOD = '?from=2026-11-01&to=2026-11-01';

    private const PLANTED = [
        'Plantedname Synthetic',
        '01099988877',
        '29001010123456',
        'Planted address',
        'Planted emergency name',
        'Planted relationship',
        '01099988866',
        'Planted appointment note',
        'Planted cancellation reason',
        'Planted discount reason',
        'Planted cancel reason',
        'Planted refund reason',
        'Planted complaint',
        'Planted findings',
        'Planted diagnosis',
        'Planted plan',
        'Planted medication',
    ];

    private QueueEntry $seen;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());

        $patient = Patient::factory()->create([
            'full_name' => 'Plantedname Synthetic',
            'phone' => '01099988877',
            'national_id' => '29001010123456',
            'address' => 'Planted address',
            'emergency_contact_name' => 'Planted emergency name',
            'emergency_contact_relationship' => 'Planted relationship',
            'emergency_contact_phone' => '01099988866',
        ]);
        $doctor = Doctor::factory()->create();

        Appointment::factory()->at('2026-11-01', '09:00')->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'note' => 'Planted appointment note']);
        Appointment::factory()->at('2026-11-01', '09:20')->cancelled()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'cancellation_reason' => 'Planted cancellation reason']);

        $this->seen = QueueEntry::factory()->number(1)->seen()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

        $charge = Charge::factory()->forEntry($this->seen)->partiallyPaid('100.00')->create(['discount' => '50.00', 'discount_reason' => 'Planted discount reason']);
        Refund::factory()->create(['charge_id' => $charge->id, 'reason' => 'Planted refund reason']);

        $other = QueueEntry::factory()->number(2)->seen()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
        Charge::factory()->forEntry($other)->cancelled()->create(['cancel_reason' => 'Planted cancel reason']);

        Encounter::factory()->forEntry($this->seen)->finalized()->create([
            'chief_complaint' => 'Planted complaint',
            'examination_findings' => 'Planted findings',
            'diagnosis' => 'Planted diagnosis',
            'treatment_plan' => 'Planted plan',
            'prescriptions' => [['medication' => 'Planted medication', 'dosage' => 'x', 'frequency' => 'x', 'duration' => 'x', 'instructions' => 'x']],
        ]);
    }

    public function test_no_report_contains_a_planted_value_or_a_patient_field(): void
    {
        foreach (self::PATHS as $path) {
            $body = $this->getJson($path.self::PERIOD)->assertOk()->getContent();

            foreach (self::PLANTED as $value) {
                $this->assertStringNotContainsString($value, $body, "{$path} exposed: {$value}");
            }

            $this->assertStringNotContainsString('patient', str_replace('new_patients', '', $body), "{$path} has a patient field");
        }
    }

    public function test_reports_never_decrypt_clinical_columns(): void
    {
        DB::table('encounters')->update([
            'chief_complaint' => 'not-ciphertext',
            'examination_findings' => 'not-ciphertext',
            'diagnosis' => 'not-ciphertext',
            'treatment_plan' => 'not-ciphertext',
            'prescriptions' => 'not-ciphertext',
        ]);

        $this->getJson('/api/v1/reports/times'.self::PERIOD)
            ->assertOk()
            ->assertJsonPath('totals.records', ['finalized' => 1, 'draft' => 0, 'none' => 1]);
    }

    public function test_no_statement_selects_a_clinical_or_patient_detail_column(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        foreach (self::PATHS as $path) {
            $this->getJson($path.self::PERIOD)->assertOk();
        }

        $sql = implode("\n", $statements);

        foreach (['chief_complaint', 'examination_findings', 'diagnosis', 'treatment_plan', 'prescriptions', 'full_name', 'national_id', 'phone', 'address', 'emergency_contact', 'reason', 'note'] as $column) {
            $this->assertStringNotContainsString($column, $sql);
        }
    }
}
