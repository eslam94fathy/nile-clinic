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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reception moves a scheduled appointment to another slot of the same doctor (US3, FR-011,
 * SC-004). The clock is fixed at Sunday 2026-11-01 08:00, clinic time; the doctor works Sunday
 * 09:00–10:00 and Tuesday 10:00–11:00 with 20-minute appointments.
 */
class RescheduleAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    private User $receptionist;

    private int $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);

        $this->doctor = $this->doctorWithSchedule();
        $this->patient = Patient::factory()->create();
        $this->appointment = $this->postJson('/api/v1/appointments', [
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ])->assertCreated()->json('data.id');
    }

    public function test_an_appointment_moves_to_another_slot_and_frees_the_old_one(): void
    {
        $this->reschedule('2026-11-03', '10:00')
            ->assertOk()
            ->assertJsonPath('data.id', $this->appointment)
            ->assertJsonPath('data.patient.id', $this->patient->id)
            ->assertJsonPath('data.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.date', '2026-11-03')
            ->assertJsonPath('data.start', '10:00')
            ->assertJsonPath('data.end', '10:20')
            ->assertJsonPath('data.status', 'scheduled')
            ->assertJsonPath('data.rescheduled_by', ['id' => $this->receptionist->id, 'name' => $this->receptionist->name])
            ->assertJsonPath('data.rescheduled_at', now()->toISOString());

        $starts = array_map(
            fn (array $slot) => "{$slot['date']} {$slot['start']}",
            $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from=2026-11-01&to=2026-11-03")->assertOk()->json('data'),
        );
        $this->assertContains('2026-11-01 09:20', $starts);
        $this->assertNotContains('2026-11-03 10:00', $starts);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unavailableTimes(): array
    {
        return [
            'outside working hours' => ['2026-11-01', '08:00'],
            'off the slot grid' => ['2026-11-01', '09:30'],
            'in the past' => ['2026-10-31', '09:00'],
            'beyond the 90-day horizon' => ['2027-01-31', '09:00'],
        ];
    }

    #[DataProvider('unavailableTimes')]
    public function test_a_time_that_is_not_an_offered_slot_is_refused(string $date, string $start): void
    {
        $this->reschedule($date, $start)
            ->assertUnprocessable()
            ->assertJsonPath('errors.start.0', __('appointments::messages.slot_unavailable'));
        $this->assertUnchanged();
    }

    public function test_a_booked_slot_is_refused(): void
    {
        Appointment::factory()->at('2026-11-03', '10:00')->create(['doctor_id' => $this->doctor->id]);

        $this->reschedule('2026-11-03', '10:00')->assertConflict()->assertJsonPath('reason', 'slot_booked');
        $this->assertUnchanged();
    }

    public function test_a_time_when_the_patient_is_busy_is_refused(): void
    {
        Appointment::factory()->at('2026-11-03', '10:00')->create(['patient_id' => $this->patient->id, 'doctor_id' => $this->doctorWithSchedule()->id]);

        $this->reschedule('2026-11-03', '10:00')->assertConflict()->assertJsonPath('reason', 'patient_busy');
        $this->assertUnchanged();
    }

    public function test_it_may_move_into_a_slot_overlapping_its_own_old_time(): void
    {
        DoctorSchedule::query()->where('doctor_id', $this->doctor->id)->update(['appointment_minutes' => 30]);

        $this->reschedule('2026-11-01', '09:30')
            ->assertOk()
            ->assertJsonPath('data.start', '09:30')
            ->assertJsonPath('data.end', '10:00');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function finalStatuses(): array
    {
        return ['cancelled' => ['cancelled'], 'no-show' => ['no_show']];
    }

    #[DataProvider('finalStatuses')]
    public function test_a_cancelled_or_no_show_appointment_cannot_be_moved(string $status): void
    {
        Appointment::query()->whereKey($this->appointment)->update(['status' => $status]);

        $this->assertNotChangeable($this->reschedule('2026-11-03', '10:00'));
    }

    public function test_an_appointment_that_has_started_cannot_be_moved(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:30', 'Africa/Cairo'));

        $this->assertNotChangeable($this->reschedule('2026-11-03', '10:00'));
    }

    public function test_an_unknown_appointment_is_not_found(): void
    {
        $this->postJson('/api/v1/appointments/999999/reschedule', ['date' => '2026-11-03', 'start' => '10:00'])->assertNotFound();
    }

    public function test_date_and_start_are_required(): void
    {
        $this->postJson("/api/v1/appointments/{$this->appointment}/reschedule", [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['date', 'start']);
    }

    public function test_a_doctor_cannot_reschedule(): void
    {
        Sanctum::actingAs($this->doctor->user);

        $this->reschedule('2026-11-03', '10:00')->assertForbidden();
        $this->assertUnchanged();
    }

    private function reschedule(string $date, string $start): TestResponse
    {
        return $this->postJson("/api/v1/appointments/{$this->appointment}/reschedule", ['date' => $date, 'start' => $start]);
    }

    private function assertNotChangeable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }

    private function assertUnchanged(): void
    {
        $appointment = Appointment::query()->findOrFail($this->appointment);

        $this->assertSame(['2026-11-01', 560, 580, null], [
            $appointment->date->toDateString(), $appointment->start_minute, $appointment->end_minute, $appointment->rescheduled_by,
        ]);
    }

    private function doctorWithSchedule(): Doctor
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()
            ->withPeriod('sunday', '09:00', '10:00')
            ->withPeriod('tuesday', '10:00', '11:00')
            ->create(['doctor_id' => $doctor->id]);

        return $doctor;
    }
}
