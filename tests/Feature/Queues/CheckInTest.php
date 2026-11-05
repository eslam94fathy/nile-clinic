<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Reception checks in the patient of today's scheduled appointment (US1, FR-001–FR-005). The clock
 * is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class CheckInTest extends TestCase
{
    use RefreshDatabase;

    private User $receptionist;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);
        $this->doctor = Doctor::factory()->create();
    }

    public function test_checking_in_adds_a_waiting_entry_and_marks_the_appointment(): void
    {
        $appointment = $this->appointment('09:20');

        $response = $this->checkIn($appointment->id)->assertCreated();

        $entry = QueueEntry::query()->sole();
        $this->assertSame([
            'id' => $entry->id,
            'queue_number' => 1,
            'status' => 'waiting',
            'walk_in' => false,
            'arrived_at' => now()->toISOString(),
            'called_at' => null,
            'finished_at' => null,
            'left_at' => null,
            'appointment' => ['id' => $appointment->id, 'start' => '09:20', 'end' => '09:40'],
            'doctor' => ['id' => $this->doctor->id, 'name' => $this->doctor->user->name, 'title' => $this->doctor->title],
            'patient' => ['id' => $appointment->patient->id, 'full_name' => $appointment->patient->full_name, 'phone' => $appointment->patient->phone],
        ], $response->json('data'));

        $this->assertSame(AppointmentStatus::CheckedIn, $appointment->refresh()->status);
        $this->assertSame($this->receptionist->id, $entry->checked_in_by);
        $this->assertSame(560, $entry->order_minute);
    }

    public function test_an_administrator_can_check_in(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->checkIn($this->appointment('09:20')->id)->assertCreated();
    }

    public function test_queue_numbers_count_up_per_doctor(): void
    {
        foreach (['09:00', '09:20', '09:40'] as $index => $start) {
            $this->checkIn($this->appointment($start)->id)
                ->assertCreated()
                ->assertJsonPath('data.queue_number', $index + 1);
        }

        $other = Appointment::factory()->at('2026-11-01', '09:00')->create();

        $this->checkIn($other->id)->assertCreated()->assertJsonPath('data.queue_number', 1);
    }

    public function test_early_and_late_arrivals_on_the_day_are_accepted(): void
    {
        $this->checkIn($this->appointment('09:20')->id)->assertCreated();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 11:00', 'Africa/Cairo'));

        $this->checkIn($this->appointment('09:40')->id)->assertCreated();
    }

    public function test_an_appointment_for_another_day_is_refused(): void
    {
        foreach (['2026-11-02', '2026-10-31'] as $date) {
            $appointment = Appointment::factory()->at($date, '09:20')->create(['doctor_id' => $this->doctor->id]);

            $this->assertNotCheckable($this->checkIn($appointment->id));
            $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);
        }

        $this->assertSame(0, QueueEntry::query()->count());
    }

    public function test_a_cancelled_appointment_is_refused(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->cancelled()->create(['doctor_id' => $this->doctor->id]);

        $this->assertNotCheckable($this->checkIn($appointment->id));
        $this->assertSame(AppointmentStatus::Cancelled, $appointment->refresh()->status);
        $this->assertSame(0, QueueEntry::query()->count());
    }

    public function test_a_no_show_appointment_is_refused(): void
    {
        $appointment = Appointment::factory()->at('2026-11-01', '09:20')->noShow()->create(['doctor_id' => $this->doctor->id]);

        $this->assertNotCheckable($this->checkIn($appointment->id));
        $this->assertSame(AppointmentStatus::NoShow, $appointment->refresh()->status);
        $this->assertSame(0, QueueEntry::query()->count());
    }

    public function test_an_appointment_already_checked_in_is_refused(): void
    {
        $appointment = $this->appointment('09:20');
        $this->checkIn($appointment->id)->assertCreated();

        $this->assertNotCheckable($this->checkIn($appointment->id));
        $this->assertSame(1, QueueEntry::query()->count());
    }

    public function test_an_appointment_of_an_inactive_doctor_can_still_be_checked_in(): void
    {
        $this->doctor->user->update(['is_active' => false]);

        $this->checkIn($this->appointment('09:20')->id)->assertCreated();
    }

    public function test_an_unknown_appointment_is_not_found(): void
    {
        $this->checkIn(999999)
            ->assertNotFound()
            ->assertExactJson(['message' => __('appointments::messages.not_found')]);
    }

    public function test_a_doctor_is_forbidden_and_nothing_is_saved(): void
    {
        $appointment = $this->appointment('09:20');
        Sanctum::actingAs($this->doctor->user);

        $this->checkIn($appointment->id)->assertForbidden();

        $this->assertSame(AppointmentStatus::Scheduled, $appointment->refresh()->status);
        $this->assertSame(0, QueueEntry::query()->count());
    }

    private function appointment(string $start): Appointment
    {
        return Appointment::factory()->at('2026-11-01', $start)->create(['doctor_id' => $this->doctor->id]);
    }

    private function checkIn(int $id): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$id}/check-in");
    }

    private function assertNotCheckable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.appointment.0', __('queues::messages.not_checkable'));
    }
}
