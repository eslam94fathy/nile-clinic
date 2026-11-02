<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Schedule, time off, and account changes are never blocked by appointments; affected
 * appointments are flagged as outside availability, computed on every read (FR-018, SC-008,
 * US2-7…9, research R7). The clock is fixed at Sunday 2026-11-01 08:00, clinic time.
 */
class OutsideAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private User $administrator;

    private User $receptionist;

    private int $sunday;

    private int $tuesday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->administrator = User::factory()->administrator()->create();
        $this->receptionist = User::factory()->receptionist()->create();

        $this->doctor = Doctor::factory()->create();
        DoctorSchedule::factory()
            ->withPeriod('sunday', '09:00', '10:00')
            ->withPeriod('tuesday', '10:00', '11:00')
            ->create(['doctor_id' => $this->doctor->id]);

        $this->sunday = $this->book('2026-11-01', '09:20');
        $this->tuesday = $this->book('2026-11-03', '10:00');
    }

    public function test_appointments_inside_availability_are_not_flagged(): void
    {
        $this->assertFlags(false, false);
    }

    public function test_whole_day_time_off_flags_that_days_appointments_and_is_not_blocked(): void
    {
        $this->asAdministrator()->postJson($this->url('time-off'), ['start_date' => '2026-11-01', 'end_date' => '2026-11-01'])->assertCreated();

        $this->assertFlags(true, false);
        $this->asReceptionist()->getJson("/api/v1/appointments/{$this->sunday}")
            ->assertJsonPath('data.date', '2026-11-01')
            ->assertJsonPath('data.start', '09:20')
            ->assertJsonPath('data.end', '09:40');
    }

    public function test_partial_time_off_flags_only_an_overlapped_appointment(): void
    {
        $this->asAdministrator()->postJson($this->url('time-off'), ['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'start_time' => '09:30', 'end_time' => '09:50'])->assertCreated();

        $this->assertFlags(true, false);
    }

    public function test_time_off_that_only_touches_an_appointment_does_not_flag_it(): void
    {
        $this->asAdministrator()->postJson($this->url('time-off'), ['start_date' => '2026-11-01', 'end_date' => '2026-11-01', 'start_time' => '09:40', 'end_time' => '10:00'])->assertCreated();

        $this->assertFlags(false, false);
    }

    public function test_removing_the_time_off_clears_the_flag(): void
    {
        $timeOff = $this->asAdministrator()->postJson($this->url('time-off'), ['start_date' => '2026-11-01', 'end_date' => '2026-11-01'])->assertCreated()->json('data.id');
        $this->assertFlags(true, false);

        $this->asAdministrator()->deleteJson($this->url("time-off/{$timeOff}"))->assertSuccessful();

        $this->assertFlags(false, false);
    }

    public function test_a_period_that_no_longer_covers_the_appointment_flags_it(): void
    {
        $this->setSchedule(20, [['sunday', '09:30', '10:00'], ['tuesday', '10:00', '11:00']]);

        $this->assertFlags(true, false);
    }

    public function test_a_wider_period_still_covers_the_appointment(): void
    {
        $this->setSchedule(20, [['sunday', '09:00', '12:00'], ['tuesday', '10:00', '11:00']]);

        $this->assertFlags(false, false);
    }

    public function test_clearing_the_schedule_flags_every_appointment(): void
    {
        $this->asAdministrator()->deleteJson($this->url('schedule'))->assertSuccessful();

        $this->assertFlags(true, true);
    }

    public function test_a_deactivated_doctor_flags_every_appointment(): void
    {
        $this->doctor->user->update(['is_active' => false]);

        $this->assertFlags(true, true);
    }

    public function test_a_new_appointment_length_alone_does_not_flag_and_slots_avoid_the_booking(): void
    {
        $this->setSchedule(30, [['sunday', '09:00', '10:00'], ['tuesday', '10:00', '11:00']]);

        $this->assertFlags(false, false);
        $slots = $this->asReceptionist()->getJson($this->url('slots').'?from=2026-11-01&to=2026-11-03')->assertOk()->json('data');
        $this->assertSame([['date' => '2026-11-03', 'start' => '10:30', 'end' => '11:00']], $slots);
    }

    public function test_cancelled_no_show_and_started_appointments_are_never_flagged(): void
    {
        $this->asAdministrator()->deleteJson($this->url('schedule'))->assertSuccessful();
        Appointment::query()->whereKey($this->tuesday)->update(['status' => AppointmentStatus::Cancelled]);
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:30', 'Africa/Cairo'));

        $this->assertFlags(false, false);

        Appointment::query()->whereKey($this->tuesday)->update(['status' => AppointmentStatus::NoShow]);

        $this->assertFlags(false, false);
    }

    public function test_the_list_can_be_filtered_by_the_flag(): void
    {
        $this->asAdministrator()->postJson($this->url('time-off'), ['start_date' => '2026-11-01', 'end_date' => '2026-11-01'])->assertCreated();

        $this->asReceptionist();
        $this->assertSame([$this->sunday], array_column($this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-03&outside_availability=1')->assertOk()->json('data'), 'id'));
        $this->assertSame([$this->tuesday], array_column($this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-03&outside_availability=0')->assertOk()->json('data'), 'id'));
    }

    private function book(string $date, string $start): int
    {
        return $this->asReceptionist()->postJson('/api/v1/appointments', [
            'patient_id' => Patient::factory()->create()->id,
            'doctor_id' => $this->doctor->id,
            'date' => $date,
            'start' => $start,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $periods
     */
    private function setSchedule(int $minutes, array $periods): void
    {
        $this->asAdministrator()->putJson($this->url('schedule'), [
            'appointment_minutes' => $minutes,
            'periods' => array_map(fn (array $p) => ['day' => $p[0], 'start' => $p[1], 'end' => $p[2]], $periods),
        ])->assertOk();
    }

    /**
     * The flag of both appointments, in the single view and in the list.
     */
    private function assertFlags(bool $sunday, bool $tuesday): void
    {
        $this->asReceptionist();

        $this->getJson("/api/v1/appointments/{$this->sunday}")->assertOk()->assertJsonPath('data.outside_availability', $sunday);
        $this->getJson("/api/v1/appointments/{$this->tuesday}")->assertOk()->assertJsonPath('data.outside_availability', $tuesday);

        $flags = array_column($this->getJson('/api/v1/appointments?from=2026-11-01&to=2026-11-03')->assertOk()->json('data'), 'outside_availability', 'id');
        $this->assertSame([$this->sunday => $sunday, $this->tuesday => $tuesday], $flags);
    }

    private function asAdministrator(): static
    {
        Sanctum::actingAs($this->administrator);

        return $this;
    }

    private function asReceptionist(): static
    {
        Sanctum::actingAs($this->receptionist);

        return $this;
    }

    private function url(string $path): string
    {
        return "/api/v1/doctors/{$this->doctor->id}/{$path}";
    }
}
