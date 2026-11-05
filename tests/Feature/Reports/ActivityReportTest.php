<?php

namespace Tests\Feature\Reports;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Appointments, the no-show rate, visits, and new patients (US3, FR-012–FR-014).
 */
class ActivityReportTest extends TestCase
{
    use RefreshDatabase;

    private const TODAY = '2026-11-01';

    private int $nextMinute = 0;

    private int $nextNumber = 1;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());
    }

    private function doctor(string $name): Doctor
    {
        return Doctor::factory()->create(['user_id' => User::factory()->doctor()->create(['name' => $name])->id]);
    }

    private function appointments(Doctor $doctor, AppointmentStatus $status, int $count = 1, string $date = self::TODAY): void
    {
        for ($i = 0; $i < $count; $i++) {
            $start = 540 + 20 * $this->nextMinute++;

            Appointment::factory()
                ->at($date, sprintf('%02d:%02d', intdiv($start, 60), $start % 60))
                ->create(['doctor_id' => $doctor->id, 'status' => $status]);
        }
    }

    private function report(array $query = []): TestResponse
    {
        return $this->getJson('/api/v1/reports/activity?'.http_build_query(['from' => self::TODAY, 'to' => self::TODAY, ...$query]));
    }

    public function test_the_full_report_has_the_exact_shape_and_figures(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        $this->appointments($amira, AppointmentStatus::NoShow);
        $this->appointments($amira, AppointmentStatus::CheckedIn, 5);
        $this->appointments($amira, AppointmentStatus::Completed, 2);
        $this->appointments($amira, AppointmentStatus::Scheduled);
        $this->appointments($amira, AppointmentStatus::Cancelled);
        $this->appointments($badr, AppointmentStatus::NoShow);

        $booked = Appointment::query()->where('doctor_id', $amira->id)->where('status', AppointmentStatus::Completed)->first();
        QueueEntry::factory()->number($this->nextNumber++)->forAppointment($booked)->seen()->create();
        QueueEntry::factory()->number($this->nextNumber++)->create(['doctor_id' => $amira->id]);
        QueueEntry::factory()->number($this->nextNumber++)->withDoctor()->create(['doctor_id' => $amira->id]);
        QueueEntry::factory()->number($this->nextNumber++)->left()->create(['doctor_id' => $amira->id]);

        $response = $this->report()->assertOk()->assertJsonStructure(['data', 'totals', 'links', 'meta']);

        $this->assertEquals([
            [
                'doctor' => ['id' => $amira->id, 'name' => 'Amira Synthetic', 'title' => $amira->title],
                'appointments' => ['scheduled' => 1, 'cancelled' => 1, 'no_show' => 1, 'checked_in' => 5, 'completed' => 2],
                'no_show_rate' => 12.5,
                'visits' => ['waiting' => 1, 'with_doctor' => 1, 'seen' => 1, 'left' => 1, 'booked' => 1, 'walk_in' => 3],
            ],
            [
                'doctor' => ['id' => $badr->id, 'name' => 'Badr Synthetic', 'title' => $badr->title],
                'appointments' => ['scheduled' => 0, 'cancelled' => 0, 'no_show' => 1, 'checked_in' => 0, 'completed' => 0],
                'no_show_rate' => 100,
                'visits' => ['waiting' => 0, 'with_doctor' => 0, 'seen' => 0, 'left' => 0, 'booked' => 0, 'walk_in' => 0],
            ],
        ], $response->json('data'));

        $this->assertEquals(
            ['scheduled' => 1, 'cancelled' => 1, 'no_show' => 2, 'checked_in' => 5, 'completed' => 2],
            $response->json('totals.appointments'),
        );

        // From the counts (2 of 9), not an average of the doctors' rates (56.25).
        $this->assertEquals(22.2, $response->json('totals.no_show_rate'));
        $this->assertEquals(
            ['waiting' => 1, 'with_doctor' => 1, 'seen' => 1, 'left' => 1, 'booked' => 1, 'walk_in' => 3],
            $response->json('totals.visits'),
        );
    }

    public function test_the_rate_is_empty_when_no_appointment_came_due(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $this->appointments($doctor, AppointmentStatus::Scheduled);
        $this->appointments($doctor, AppointmentStatus::Cancelled);

        $this->report()
            ->assertJsonPath('data.0.no_show_rate', null)
            ->assertJsonPath('totals.no_show_rate', null);
    }

    public function test_an_undone_check_in_leaves_no_visit_and_its_appointment_counts_as_scheduled(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $appointment = Appointment::factory()->at(self::TODAY, '09:00')->create(['doctor_id' => $doctor->id]);

        $entry = $this->postJson("/api/v1/appointments/{$appointment->id}/check-in")->assertCreated()->json('data.id');

        $this->report()->assertJsonPath('totals.appointments.checked_in', 1)->assertJsonPath('totals.visits.waiting', 1);

        $this->deleteJson("/api/v1/queue/{$entry}")->assertNoContent();

        $this->report()
            ->assertJsonPath('totals.appointments.scheduled', 1)
            ->assertJsonPath('totals.appointments.checked_in', 0)
            ->assertJsonPath('totals.visits.waiting', 0);
    }

    public function test_a_past_appointment_nobody_resolved_counts_as_scheduled_not_as_a_no_show(): void
    {
        $this->appointments($this->doctor('Amira Synthetic'), AppointmentStatus::Scheduled, 1, '2026-10-20');

        $this->report(['from' => '2026-10-20', 'to' => '2026-10-20'])
            ->assertJsonPath('totals.appointments.scheduled', 1)
            ->assertJsonPath('totals.appointments.no_show', 0);
    }

    public function test_a_rescheduled_appointment_counts_once_on_its_current_date(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        $appointment = Appointment::factory()->at('2026-11-03', '09:00')->create(['doctor_id' => $doctor->id]);

        $appointment->update(['date' => '2026-11-05', 'rescheduled_by' => User::factory()->receptionist()->create()->id, 'rescheduled_at' => now()]);

        $this->report(['from' => '2026-11-03', 'to' => '2026-11-03'])->assertJsonPath('totals.appointments.scheduled', 0);
        $this->report(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertJsonPath('totals.appointments.scheduled', 1);
    }

    public function test_a_period_with_future_dates_shows_upcoming_appointments(): void
    {
        $this->appointments($this->doctor('Amira Synthetic'), AppointmentStatus::Scheduled, 2, '2026-12-01');

        $this->report(['to' => '2026-12-31'])->assertJsonPath('totals.appointments.scheduled', 2);
    }

    public function test_new_patients_count_on_their_clinic_day(): void
    {
        Patient::factory()->create(['created_at' => '2026-11-04 21:59:00']);
        Patient::factory()->create(['created_at' => '2026-11-04 22:00:00']);
        Patient::factory()->create(['created_at' => '2026-11-05 21:59:00']);
        Patient::factory()->create(['created_at' => '2026-11-05 22:00:00']);

        $this->report(['from' => '2026-11-05', 'to' => '2026-11-05'])->assertJsonPath('totals.new_patients', 2);
    }

    public function test_new_patients_are_left_out_with_a_doctor_filter(): void
    {
        $doctor = $this->doctor('Amira Synthetic');
        Patient::factory()->create();

        $this->report()->assertJsonPath('totals.new_patients', 1);
        $this->report(['doctor_id' => $doctor->id])->assertOk()->assertJsonMissingPath('totals.new_patients');
    }

    public function test_a_doctor_filter_applies_to_appointments_and_visits(): void
    {
        $amira = $this->doctor('Amira Synthetic');
        $badr = $this->doctor('Badr Synthetic');

        $this->appointments($amira, AppointmentStatus::Scheduled);
        QueueEntry::factory()->number($this->nextNumber++)->create(['doctor_id' => $amira->id]);
        $this->appointments($badr, AppointmentStatus::Cancelled);

        $this->report(['doctor_id' => $badr->id])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $badr->id)
            ->assertJsonPath('totals.appointments.cancelled', 1)
            ->assertJsonPath('totals.appointments.scheduled', 0)
            ->assertJsonPath('totals.visits.waiting', 0);
    }

    public function test_doctors_are_paged_by_name_and_totals_cover_every_page(): void
    {
        $this->appointments($this->doctor('Badr Synthetic'), AppointmentStatus::Scheduled);
        $amira = $this->doctor('Amira Synthetic');
        $this->appointments($amira, AppointmentStatus::Scheduled);

        $this->report(['per_page' => 1])
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.doctor.id', $amira->id)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('totals.appointments.scheduled', 2);
    }

    public function test_a_period_with_no_activity_has_zero_totals(): void
    {
        $this->report(['from' => '2026-10-01', 'to' => '2026-10-01'])
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('totals.appointments.scheduled', 0)
            ->assertJsonPath('totals.no_show_rate', null)
            ->assertJsonPath('totals.visits.walk_in', 0)
            ->assertJsonPath('totals.new_patients', 0);
    }
}
