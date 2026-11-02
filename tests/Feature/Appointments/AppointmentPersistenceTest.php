<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database backstop (research R3): at most one scheduled appointment per doctor slot start;
 * any number of cancelled or no-show rows; referenced rows cannot be deleted.
 */
class AppointmentPersistenceTest extends TestCase
{
    use RefreshDatabase;

    private Doctor $doctor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->doctor = Doctor::factory()->create();
    }

    public function test_two_scheduled_appointments_cannot_share_a_slot_start(): void
    {
        $this->slot()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        $this->slot()->create();
    }

    public function test_a_cancelled_and_a_scheduled_appointment_can_share_a_slot_start(): void
    {
        $this->slot()->cancelled()->create();
        $this->slot()->create();

        $this->assertSame(2, Appointment::query()->count());
    }

    public function test_cancelled_and_no_show_appointments_can_share_a_slot_start(): void
    {
        $this->slot()->cancelled()->create();
        $this->slot()->cancelled()->create();
        $this->slot()->noShow()->create();

        $this->assertSame(3, Appointment::query()->count());
    }

    public function test_the_scheduled_flag_follows_the_status(): void
    {
        $appointment = $this->slot()->create();

        $this->assertSame(1, $this->flag($appointment));

        $appointment->update(['status' => AppointmentStatus::Cancelled]);

        $this->assertNull($this->flag($appointment));
    }

    public function test_a_patient_with_an_appointment_cannot_be_deleted(): void
    {
        $appointment = $this->slot()->create();

        $this->expectException(QueryException::class);

        DB::table('patients')->where('id', $appointment->patient_id)->delete();
    }

    public function test_a_doctor_with_an_appointment_cannot_be_deleted(): void
    {
        $this->slot()->create();

        $this->expectException(QueryException::class);

        DB::table('doctors')->where('id', $this->doctor->id)->delete();
    }

    public function test_a_staff_account_that_booked_an_appointment_cannot_be_deleted(): void
    {
        $appointment = $this->slot()->create();

        $this->expectException(QueryException::class);

        DB::table('users')->where('id', $appointment->booked_by)->delete();
    }

    private function slot(): \Database\Factories\AppointmentFactory
    {
        return Appointment::factory()->at('2026-11-08', '09:20')->state(['doctor_id' => $this->doctor->id]);
    }

    private function flag(Appointment $appointment): ?int
    {
        $value = DB::table('appointments')->where('id', $appointment->id)->value('scheduled_flag');

        return $value === null ? null : (int) $value;
    }
}
