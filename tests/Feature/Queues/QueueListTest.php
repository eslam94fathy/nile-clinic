<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception sees one doctor's list and the clinic's lists for today, in queue order (US2, FR-007–
 * FR-009, research R4). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class QueueListTest extends TestCase
{
    use RefreshDatabase;

    private const ENTRY_KEYS = ['id', 'queue_number', 'status', 'walk_in', 'arrived_at', 'called_at', 'finished_at', 'left_at', 'appointment', 'doctor', 'patient'];

    private Doctor $doctorA;

    private Doctor $doctorB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctorA = Doctor::factory()->create();
        $this->doctorB = Doctor::factory()->create();

        foreach (['09:40', '09:00', '09:20'] as $start) {
            $this->checkIn($this->doctorA, $start);
        }

        $this->checkIn($this->doctorB, '09:00');
    }

    public function test_a_doctors_list_is_in_queue_order_with_the_contract_fields(): void
    {
        $response = $this->getJson("/api/v1/doctors/{$this->doctorA->id}/queue")->assertOk();

        $this->assertSame(['09:00', '09:20', '09:40'], array_column(array_column($response->json('data'), 'appointment'), 'start'));
        $this->assertSame([2, 3, 1], array_column($response->json('data'), 'queue_number'));

        foreach ($response->json('data') as $entry) {
            $this->assertSame(self::ENTRY_KEYS, array_keys($entry));
            $this->assertSame(['id', 'full_name', 'phone'], array_keys($entry['patient']));
        }
    }

    public function test_lists_never_contain_the_note_or_sensitive_patient_fields(): void
    {
        $patient = Patient::factory()->withNationalId()->withAddress()->withEmergencyContact()->create();
        $appointment = Appointment::factory()->at('2026-11-01', '10:00')->create([
            'doctor_id' => $this->doctorA->id,
            'patient_id' => $patient->id,
            'note' => 'Synthetic booking note',
        ]);
        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated();

        foreach (["/api/v1/doctors/{$this->doctorA->id}/queue", '/api/v1/queue'] as $uri) {
            $content = $this->getJson($uri)->assertOk()->getContent();

            foreach (['note', 'national_id', 'address', 'emergency_contact'] as $field) {
                $this->assertStringNotContainsString("\"{$field}\"", $content);
            }

            $this->assertStringNotContainsString('Synthetic booking note', $content);
            $this->assertStringNotContainsString($patient->national_id, $content);
        }
    }

    public function test_the_clinics_list_is_by_doctor_then_queue_order(): void
    {
        $response = $this->getJson('/api/v1/queue')->assertOk()->assertJsonPath('meta.total', 4);

        $this->assertSame(
            [$this->doctorA->id, $this->doctorA->id, $this->doctorA->id, $this->doctorB->id],
            array_column(array_column($response->json('data'), 'doctor'), 'id'),
        );
        $this->assertSame(['09:00', '09:20', '09:40', '09:00'], array_column(array_column($response->json('data'), 'appointment'), 'start'));
    }

    public function test_the_clinics_list_is_paged_and_filtered_by_status(): void
    {
        $this->getJson('/api/v1/queue?per_page=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 4);

        $this->getJson('/api/v1/queue?status=waiting')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('/api/v1/queue?status=seen')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_invalid_list_parameters_are_refused(): void
    {
        $this->getJson('/api/v1/queue?status=done')->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->getJson('/api/v1/queue?per_page=101')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    public function test_a_doctor_with_no_entries_has_an_empty_list(): void
    {
        $this->getJson('/api/v1/doctors/'.Doctor::factory()->create()->id.'/queue')
            ->assertOk()
            ->assertExactJson(['data' => []]);
    }

    public function test_an_unknown_doctor_is_not_found(): void
    {
        $this->getJson('/api/v1/doctors/999999/queue')
            ->assertNotFound()
            ->assertExactJson(['message' => __('doctors::messages.doctor_not_found')]);
    }

    public function test_entries_of_other_days_never_appear(): void
    {
        $doctorC = Doctor::factory()->create();
        QueueEntry::factory()->create(['doctor_id' => $doctorC->id, 'date' => '2026-10-31']);

        $this->getJson("/api/v1/doctors/{$doctorC->id}/queue")->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/v1/queue')->assertOk()->assertJsonPath('meta.total', 4);
    }

    public function test_a_doctor_is_forbidden_on_both_lists(): void
    {
        Sanctum::actingAs($this->doctorA->user);

        $this->getJson('/api/v1/queue')->assertForbidden();
        $this->getJson("/api/v1/doctors/{$this->doctorA->id}/queue")->assertForbidden();
    }

    private function checkIn(Doctor $doctor, string $start): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $doctor->id]);

        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated();
    }
}
