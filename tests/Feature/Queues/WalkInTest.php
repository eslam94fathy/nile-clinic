<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception adds a registered patient without an appointment to a doctor's list today (US4, FR-006,
 * research R6). The clock is fixed at Sunday 2026-11-01 09:50, clinic time.
 */
class WalkInTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:50', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '11:00')->create(['doctor_id' => $this->doctor->id]);
        $this->patient = Patient::factory()->create();

        foreach (['09:40', '10:00'] as $start) {
            $appointment = Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $this->doctor->id]);
            $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated();
        }
    }

    public function test_a_walk_in_waits_with_the_next_number_placed_by_arrival_time(): void
    {
        $this->walkIn()
            ->assertCreated()
            ->assertJsonPath('data.queue_number', 3)
            ->assertJsonPath('data.status', 'waiting')
            ->assertJsonPath('data.walk_in', true)
            ->assertJsonPath('data.appointment', null)
            ->assertJsonPath('data.arrived_at', now()->toISOString())
            ->assertJsonPath('data.patient.id', $this->patient->id)
            ->assertJsonPath('data.doctor.id', $this->doctor->id);

        $this->assertSame([1, 3, 2], $this->listedNumbers());
    }

    public function test_a_walk_in_does_not_change_the_doctors_slots(): void
    {
        $before = $this->slots()->assertOk()->json('data');

        $this->walkIn()->assertCreated();

        $this->assertNotEmpty($before);
        $this->assertSame($before, $this->slots()->assertOk()->json('data'));
    }

    public function test_at_an_equal_time_the_queue_number_decides(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 10:00', 'Africa/Cairo'));

        $this->walkIn()->assertCreated()->assertJsonPath('data.queue_number', 3);

        $this->assertSame([1, 2, 3], $this->listedNumbers());
    }

    public function test_a_patient_already_in_the_list_is_refused_until_they_have_left(): void
    {
        $id = $this->walkIn()->assertCreated()->json('data.id');

        $this->walkIn()
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', __('queues::messages.already_in_queue'));

        QueueEntry::query()->findOrFail($id)->update(['status' => QueueStatus::Left]);

        $this->walkIn()->assertCreated()->assertJsonPath('data.queue_number', 4);
    }

    public function test_an_unknown_patient_is_refused(): void
    {
        $this->walkIn(['patient_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', __('queues::messages.patient_not_found'));
    }

    public function test_an_unknown_doctor_is_refused(): void
    {
        $this->walkIn(['doctor_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('queues::messages.doctor_not_found'));
    }

    public function test_an_inactive_doctor_is_refused(): void
    {
        $this->doctor->user->update(['is_active' => false]);

        $this->walkIn()
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('queues::messages.doctor_inactive'));
    }

    public function test_missing_fields_are_refused(): void
    {
        $this->postJson('/api/v1/queue/walk-ins', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id', 'doctor_id']);
    }

    public function test_a_doctor_is_forbidden(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->walkIn()->assertForbidden();

        $this->assertSame(2, QueueEntry::query()->count());
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function walkIn(array $change = []): TestResponse
    {
        return $this->postJson('/api/v1/queue/walk-ins', array_merge([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
        ], $change));
    }

    private function slots(): TestResponse
    {
        return $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from=2026-11-01&to=2026-11-01");
    }

    /**
     * @return list<int>
     */
    private function listedNumbers(): array
    {
        return array_column($this->getJson("/api/v1/doctors/{$this->doctor->id}/queue")->assertOk()->json('data'), 'queue_number');
    }
}
