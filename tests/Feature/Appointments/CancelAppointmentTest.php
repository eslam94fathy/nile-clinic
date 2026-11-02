<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception cancels a scheduled appointment that has not started; the slot is freed (US3,
 * FR-012, FR-014). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class CancelAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private User $receptionist;

    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $this->doctor->id]);
        $this->appointment = $this->book(Patient::factory()->create())->assertCreated()->json('data.id');
    }

    public function test_cancelling_records_who_when_and_why_and_frees_the_slot(): void
    {
        $this->cancel(['reason' => '  Synthetic reason  '])
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.status_changed_by', ['id' => $this->receptionist->id, 'name' => $this->receptionist->name])
            ->assertJsonPath('data.status_changed_at', now()->toISOString())
            ->assertJsonPath('data.cancellation_reason', 'Synthetic reason');

        $starts = array_column($this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from=2026-11-01&to=2026-11-01")->json('data'), 'start');
        $this->assertSame(['09:00', '09:20', '09:40'], $starts);
    }

    public function test_the_freed_slot_can_be_booked_for_another_patient(): void
    {
        $this->cancel()->assertOk();

        $this->book(Patient::factory()->create())->assertCreated();
        $this->assertSame('cancelled', Appointment::query()->findOrFail($this->appointment)->status->value);
    }

    public function test_the_reason_is_optional(): void
    {
        $this->postJson("/api/v1/appointments/{$this->appointment}/cancel")
            ->assertOk()
            ->assertJsonPath('data.cancellation_reason', null);
    }

    public function test_a_reason_longer_than_255_characters_is_rejected(): void
    {
        $this->cancel(['reason' => str_repeat('a', 256)])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->assertSame('scheduled', Appointment::query()->findOrFail($this->appointment)->status->value);
    }

    public function test_a_cancelled_appointment_cannot_be_cancelled_again(): void
    {
        $this->cancel()->assertOk();

        $this->assertNotChangeable($this->cancel());
    }

    public function test_an_appointment_that_has_started_cannot_be_cancelled(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:20', 'Africa/Cairo'));

        $this->assertNotChangeable($this->cancel());
    }

    public function test_an_unknown_appointment_is_not_found(): void
    {
        $this->postJson('/api/v1/appointments/999999/cancel')->assertNotFound();
    }

    public function test_a_doctor_cannot_cancel(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->cancel()->assertForbidden();
        $this->assertSame('scheduled', Appointment::query()->findOrFail($this->appointment)->status->value);
    }

    private function book(Patient $patient): TestResponse
    {
        return $this->postJson('/api/v1/appointments', [
            'patient_id' => $patient->id,
            'doctor_id' => $this->doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function cancel(array $body = []): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$this->appointment}/cancel", $body);
    }

    private function assertNotChangeable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }
}
