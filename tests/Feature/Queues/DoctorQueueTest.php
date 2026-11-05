<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A doctor sees their own list, calls the next patient, and finishes the visit (US3, FR-010–FR-013,
 * research R5). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class DoctorQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Doctor $doctor;

    private Patient $patient1;

    private Patient $patient2;

    private Appointment $appointment1;

    private Appointment $appointment2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();

        $this->doctor = Doctor::factory()->create();
        $this->patient1 = Patient::factory()->create();
        $this->patient2 = Patient::factory()->create();
        $this->appointment1 = $this->checkedIn($this->doctor, $this->patient1, '09:20');
        $this->appointment2 = $this->checkedIn($this->doctor, $this->patient2, '09:00');

        Sanctum::actingAs($this->doctor->user);
    }

    public function test_the_own_list_is_in_queue_order_with_the_own_shape(): void
    {
        $response = $this->getJson('/api/v1/doctors/me/queue')->assertOk();

        $this->assertSame([$this->patient2->id, $this->patient1->id], array_column(array_column($response->json('data'), 'patient'), 'id'));

        foreach ($response->json('data') as $entry) {
            $this->assertSame(['id', 'queue_number', 'status', 'walk_in', 'arrived_at', 'called_at', 'finished_at', 'left_at', 'appointment', 'patient'], array_keys($entry));
            $this->assertSame(['id', 'full_name', 'sex', 'date_of_birth'], array_keys($entry['patient']));
        }

        $this->assertSame($this->patient2->date_of_birth->toDateString(), $response->json('data.0.patient.date_of_birth'));
        $this->assertSame($this->patient2->sex->value, $response->json('data.0.patient.sex'));
    }

    public function test_call_next_picks_the_first_in_queue_order(): void
    {
        $this->callNext()
            ->assertOk()
            ->assertJsonPath('data.patient.id', $this->patient2->id)
            ->assertJsonPath('data.status', 'with_doctor')
            ->assertJsonPath('data.called_at', now()->toISOString());
    }

    public function test_call_next_is_refused_while_a_patient_is_with_the_doctor(): void
    {
        $this->callNext()->assertOk();

        $this->callNext()
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', __('queues::messages.visit_in_progress'));
    }

    public function test_finishing_marks_the_entry_seen_and_the_appointment_completed(): void
    {
        $this->callNext()->assertOk();

        $this->finish()
            ->assertOk()
            ->assertJsonPath('data.patient.id', $this->patient2->id)
            ->assertJsonPath('data.status', 'seen')
            ->assertJsonPath('data.finished_at', now()->toISOString());

        $this->assertSame(AppointmentStatus::Completed, $this->appointment2->refresh()->status);
        $this->assertSame(AppointmentStatus::CheckedIn, $this->appointment1->refresh()->status);
    }

    public function test_finishing_without_a_current_visit_is_refused(): void
    {
        $this->finish()
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', __('queues::messages.no_current_visit'));

        $this->callNext()->assertOk();
        $this->finish()->assertOk();

        $this->finish()
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', __('queues::messages.no_current_visit'));
    }

    public function test_the_whole_list_moves_until_nobody_is_waiting(): void
    {
        $this->callNext()->assertOk()->assertJsonPath('data.patient.id', $this->patient2->id);
        $this->finish()->assertOk();
        $this->callNext()->assertOk()->assertJsonPath('data.patient.id', $this->patient1->id);
        $this->finish()->assertOk();

        $this->callNext()
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', __('queues::messages.nobody_waiting'));
    }

    public function test_call_next_skips_a_patient_who_is_with_another_doctor(): void
    {
        $this->seeEveryone();

        $patient3 = Patient::factory()->create();
        $other = Doctor::factory()->create();
        $this->checkedIn($this->doctor, $patient3, '09:40');
        $this->checkedIn($other, $patient3, '10:00');

        Sanctum::actingAs($other->user);
        $this->callNext()->assertOk()->assertJsonPath('data.patient.id', $patient3->id);

        Sanctum::actingAs($this->doctor->user);
        $this->callNext()
            ->assertUnprocessable()
            ->assertJsonPath('errors.queue.0', __('queues::messages.nobody_waiting'));

        Sanctum::actingAs($other->user);
        $this->finish()->assertOk();

        Sanctum::actingAs($this->doctor->user);
        $this->callNext()->assertOk()->assertJsonPath('data.patient.id', $patient3->id);
    }

    public function test_a_doctor_never_sees_another_doctors_entries(): void
    {
        $other = Doctor::factory()->create();
        $patient3 = Patient::factory()->create();
        $this->checkedIn($other, $patient3, '09:00');

        Sanctum::actingAs($this->doctor->user);

        $ids = array_column(array_column($this->getJson('/api/v1/doctors/me/queue')->assertOk()->json('data'), 'patient'), 'id');

        $this->assertNotContains($patient3->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_a_doctor_without_a_profile_is_not_found(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->getJson('/api/v1/doctors/me/queue')->assertNotFound();
        $this->callNext()->assertNotFound();
        $this->finish()->assertNotFound();
    }

    public function test_reception_is_forbidden(): void
    {
        foreach ([$this->receptionist, User::factory()->administrator()->create()] as $actor) {
            Sanctum::actingAs($actor);

            $this->getJson('/api/v1/doctors/me/queue')->assertForbidden();
            $this->callNext()->assertForbidden();
            $this->finish()->assertForbidden();
        }
    }

    private function seeEveryone(): void
    {
        foreach ([1, 2] as $ignored) {
            $this->callNext()->assertOk();
            $this->finish()->assertOk();
        }
    }

    private function checkedIn(Doctor $doctor, Patient $patient, string $start): Appointment
    {
        $appointment = Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $doctor->id, 'patient_id' => $patient->id]);

        Sanctum::actingAs($this->receptionist);
        $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated();

        return $appointment;
    }

    private function callNext(): TestResponse
    {
        return $this->postJson('/api/v1/doctors/me/queue/call-next');
    }

    private function finish(): TestResponse
    {
        return $this->postJson('/api/v1/doctors/me/queue/finish');
    }
}
