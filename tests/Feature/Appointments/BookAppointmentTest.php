<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reception books a patient into a free slot (US1, FR-001–FR-007, SC-003). The clock is fixed at
 * Sunday 2026-11-01 08:00, clinic time; the doctor works Sunday 09:00–10:00 and Tuesday
 * 10:00–11:00 with 20-minute appointments.
 */
class BookAppointmentTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    private Patient $patient;

    private User $receptionist;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        $this->receptionist = User::factory()->receptionist()->create();
        Sanctum::actingAs($this->receptionist);

        $this->doctor = $this->doctorWithSchedule();
        $this->patient = Patient::factory()->create();
    }

    public function test_a_receptionist_books_a_free_slot(): void
    {
        $response = $this->book(['note' => 'Synthetic first visit'])->assertCreated();

        $appointment = Appointment::query()->sole();
        $this->assertSame([
            'id' => $appointment->id,
            'date' => '2026-11-01',
            'start' => '09:20',
            'end' => '09:40',
            'status' => 'scheduled',
            'outside_availability' => false,
            'doctor' => ['id' => $this->doctor->id, 'name' => $this->doctor->user->name, 'title' => $this->doctor->title],
            'patient' => ['id' => $this->patient->id, 'full_name' => $this->patient->full_name, 'phone' => $this->patient->phone],
            'note' => 'Synthetic first visit',
            'booked_by' => ['id' => $this->receptionist->id, 'name' => $this->receptionist->name],
            'booked_at' => $appointment->created_at->toISOString(),
            'rescheduled_by' => null,
            'rescheduled_at' => null,
            'status_changed_by' => null,
            'status_changed_at' => null,
            'cancellation_reason' => null,
        ], $response->json('data'));
    }

    public function test_an_administrator_can_book(): void
    {
        Sanctum::actingAs(User::factory()->administrator()->create());

        $this->book()->assertCreated();
    }

    public function test_an_empty_note_is_stored_as_null(): void
    {
        $this->book(['note' => '   '])->assertCreated()->assertJsonPath('data.note', null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function unavailableTimes(): array
    {
        return [
            'outside working hours' => ['2026-11-01', '08:00'],
            'off the slot grid' => ['2026-11-01', '09:30'],
            'a day without a period' => ['2026-11-02', '09:00'],
            'in the past' => ['2026-10-31', '09:00'],
            'beyond the 90-day horizon' => ['2027-01-31', '09:00'],
        ];
    }

    #[DataProvider('unavailableTimes')]
    public function test_a_time_that_is_not_an_offered_slot_is_refused(string $date, string $start): void
    {
        $this->assertSlotUnavailable($this->book(['date' => $date, 'start' => $start]));
    }

    public function test_a_slot_that_has_started_today_is_refused(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 09:10', 'Africa/Cairo'));

        $this->assertSlotUnavailable($this->book(['start' => '09:00']));
    }

    public function test_the_last_day_of_the_horizon_is_bookable(): void
    {
        // 2027-01-30 is a Saturday, 90 days after 2026-11-01; 2027-01-31 (a Sunday) is beyond it.
        $doctor = $this->doctorWithSchedule('saturday', '09:00', '10:00');

        $this->book(['doctor_id' => $doctor->id, 'date' => '2027-01-30', 'start' => '09:00'])->assertCreated();
    }

    public function test_whole_day_time_off_makes_the_slot_unavailable(): void
    {
        DoctorTimeOff::factory()->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-01']);

        $this->assertSlotUnavailable($this->book());
    }

    public function test_partial_time_off_makes_an_overlapping_slot_unavailable(): void
    {
        DoctorTimeOff::factory()->partial('09:20', '09:40')->create(['doctor_id' => $this->doctor->id, 'start_date' => '2026-11-01', 'end_date' => '2026-11-01']);

        $this->assertSlotUnavailable($this->book());
        $this->book(['start' => '09:40'])->assertCreated();
    }

    public function test_an_inactive_doctor_cannot_be_booked(): void
    {
        $this->doctor->user->update(['is_active' => false]);

        $this->assertSlotUnavailable($this->book());
    }

    public function test_a_doctor_without_a_schedule_cannot_be_booked(): void
    {
        $this->assertSlotUnavailable($this->book(['doctor_id' => Doctor::factory()->create()->id]));
    }

    public function test_a_booked_slot_cannot_be_booked_again(): void
    {
        $this->book()->assertCreated();

        $this->book(['patient_id' => Patient::factory()->create()->id])
            ->assertConflict()
            ->assertExactJson(['message' => __('appointments::messages.slot_booked'), 'reason' => 'slot_booked']);
        $this->assertSame(1, Appointment::query()->count());
    }

    public function test_a_slot_overlapping_an_older_longer_appointment_is_taken(): void
    {
        Appointment::factory()->at('2026-11-01', '09:20', 30)->create(['doctor_id' => $this->doctor->id]);

        $this->book(['start' => '09:40'])->assertConflict()->assertJsonPath('reason', 'slot_booked');
    }

    public function test_a_patient_cannot_be_in_two_places_at_once(): void
    {
        $this->book()->assertCreated();
        $other = $this->doctorWithSchedule('sunday', '09:00', '10:00');

        $this->book(['doctor_id' => $other->id])->assertConflict()->assertJsonPath('reason', 'patient_busy');
        $this->book(['date' => '2026-11-03', 'start' => '10:00'])->assertCreated();
    }

    public function test_a_cancelled_appointment_does_not_hold_its_slot(): void
    {
        Appointment::factory()->at('2026-11-01', '09:20')->cancelled()->create(['doctor_id' => $this->doctor->id]);

        $this->book()->assertCreated();
    }

    public function test_an_unknown_patient_is_a_validation_error(): void
    {
        $this->book(['patient_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.patient_id.0', __('appointments::messages.patient_not_found'));
        $this->assertSame(0, Appointment::query()->count());
    }

    public function test_an_unknown_doctor_is_a_validation_error(): void
    {
        $this->book(['doctor_id' => 999999])
            ->assertUnprocessable()
            ->assertJsonPath('errors.doctor_id.0', __('appointments::messages.doctor_not_found'));
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidBodies(): array
    {
        return [
            'start without a leading zero' => [['start' => '9:20'], 'start'],
            'date in another format' => [['date' => '01-11-2026'], 'date'],
            'note too long' => [['note' => str_repeat('a', 501)], 'note'],
            'patient id not a number' => [['patient_id' => 'abc'], 'patient_id'],
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     */
    #[DataProvider('invalidBodies')]
    public function test_invalid_bodies_are_rejected(array $change, string $field): void
    {
        $this->book($change)->assertUnprocessable()->assertJsonValidationErrors([$field]);
    }

    public function test_every_field_but_the_note_is_required(): void
    {
        $this->postJson('/api/v1/appointments', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['patient_id', 'doctor_id', 'date', 'start'])
            ->assertJsonMissingValidationErrors(['note']);
    }

    public function test_a_doctor_cannot_book(): void
    {
        Sanctum::actingAs(User::factory()->doctor()->create());

        $this->book()->assertForbidden();
        $this->assertSame(0, Appointment::query()->count());
    }

    /**
     * @param  array<string, mixed>  $change
     */
    private function book(array $change = []): TestResponse
    {
        return $this->postJson('/api/v1/appointments', array_merge([
            'patient_id' => $this->patient->id,
            'doctor_id' => $this->doctor->id,
            'date' => '2026-11-01',
            'start' => '09:20',
        ], $change));
    }

    private function assertSlotUnavailable(TestResponse $response): void
    {
        $response->assertUnprocessable()->assertJsonPath('errors.start.0', __('appointments::messages.slot_unavailable'));
        $this->assertSame(0, Appointment::query()->count());
    }

    private function doctorWithSchedule(?string $day = null, string $start = '09:00', string $end = '10:00'): Doctor
    {
        $doctor = Doctor::factory()->create();
        $factory = $day === null
            ? DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->withPeriod('tuesday', '10:00', '11:00')
            : DoctorSchedule::factory()->withPeriod($day, $start, $end);
        $factory->create(['doctor_id' => $doctor->id]);

        return $doctor;
    }
}
