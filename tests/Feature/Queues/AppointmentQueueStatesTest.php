<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Checked-in and completed appointments keep their slot and refuse feature 006's changes (FR-005,
 * FR-012, research R2). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class AppointmentQueueStatesTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Appointment $appointment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->receptionist()->create());

        $this->doctor = $this->doctorWithSundayHours();
        $this->appointment = Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id]);
    }

    /**
     * @return array<string, array{AppointmentStatus}>
     */
    public static function holdingStatuses(): array
    {
        return [
            'checked in' => [AppointmentStatus::CheckedIn],
            'completed' => [AppointmentStatus::Completed],
        ];
    }

    #[DataProvider('holdingStatuses')]
    public function test_the_slot_is_not_offered(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $starts = array_column($this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?from=2026-11-01&to=2026-11-01")->json('data'), 'start');

        $this->assertSame(['09:00', '09:40'], $starts);
    }

    #[DataProvider('holdingStatuses')]
    public function test_the_slot_cannot_be_booked_for_another_patient(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->book(Patient::factory()->create()->id, $this->doctor->id)
            ->assertConflict()
            ->assertJsonPath('reason', 'slot_booked');
    }

    #[DataProvider('holdingStatuses')]
    public function test_the_patient_cannot_be_booked_with_a_second_doctor_at_that_time(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->book($this->appointment->patient_id, $this->doctorWithSundayHours()->id)
            ->assertConflict()
            ->assertJsonPath('reason', 'patient_busy');
    }

    #[DataProvider('holdingStatuses')]
    public function test_it_cannot_be_rescheduled(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/reschedule", ['date' => '2026-11-01', 'start' => '09:40'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }

    #[DataProvider('holdingStatuses')]
    public function test_it_cannot_be_cancelled(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/cancel")
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }

    #[DataProvider('holdingStatuses')]
    public function test_it_cannot_be_marked_no_show(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:30', 'Africa/Cairo'));

        $this->postJson("/api/v1/appointments/{$this->appointment->id}/no-show")
            ->assertUnprocessable()
            ->assertJsonPath('errors.appointment.0', __('appointments::messages.not_changeable'));
    }

    #[DataProvider('holdingStatuses')]
    public function test_the_database_refuses_a_second_scheduled_row_at_the_same_slot(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->expectException(UniqueConstraintViolationException::class);

        Appointment::factory()->at('2026-11-01', '09:20')->create(['doctor_id' => $this->doctor->id]);
    }

    #[DataProvider('holdingStatuses')]
    public function test_it_can_be_found_by_the_new_status(AppointmentStatus $status): void
    {
        $this->appointment->update(['status' => $status]);

        $this->getJson("/api/v1/appointments?from=2026-11-01&to=2026-11-01&status={$status->value}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->appointment->id)
            ->assertJsonPath('data.0.status', $status->value);
    }

    private function doctorWithSundayHours(): Doctor
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $doctor->id]);

        return $doctor;
    }

    private function book(int $patientId, int $doctorId): TestResponse
    {
        return $this->postJson('/api/v1/appointments', [
            'patient_id' => $patientId,
            'doctor_id' => $doctorId,
            'date' => '2026-11-01',
            'start' => '09:20',
        ]);
    }
}
