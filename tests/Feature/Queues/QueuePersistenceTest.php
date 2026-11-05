<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database backstops of the queue (research R3): unique indexes, the generated column, and
 * foreign keys that refuse deletion.
 */
class QueuePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_queue_number_is_unique_per_doctor_and_date(): void
    {
        $doctor = Doctor::factory()->create();
        QueueEntry::factory()->number(1)->create(['doctor_id' => $doctor->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        QueueEntry::factory()->number(1)->create(['doctor_id' => $doctor->id]);
    }

    public function test_an_appointment_is_in_a_queue_at_most_once(): void
    {
        $appointment = Appointment::factory()->create();
        QueueEntry::factory()->forAppointment($appointment)->number(1)->create();

        $this->expectException(UniqueConstraintViolationException::class);

        QueueEntry::factory()->forAppointment($appointment)->number(2)->create();
    }

    public function test_walk_ins_without_an_appointment_do_not_collide(): void
    {
        $doctor = Doctor::factory()->create();

        QueueEntry::factory()->number(1)->create(['doctor_id' => $doctor->id]);
        QueueEntry::factory()->number(2)->create(['doctor_id' => $doctor->id]);

        $this->assertSame(2, QueueEntry::query()->whereNull('appointment_id')->count());
    }

    public function test_a_doctor_has_one_patient_with_them_per_date(): void
    {
        $doctor = Doctor::factory()->create();
        QueueEntry::factory()->withDoctor()->number(1)->create(['doctor_id' => $doctor->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        QueueEntry::factory()->withDoctor()->number(2)->create(['doctor_id' => $doctor->id]);
    }

    public function test_a_doctor_may_have_a_patient_with_them_on_different_dates(): void
    {
        $doctor = Doctor::factory()->create();

        QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id, 'date' => '2026-11-01']);
        QueueEntry::factory()->withDoctor()->create(['doctor_id' => $doctor->id, 'date' => '2026-11-02']);

        $this->assertSame(2, QueueEntry::query()->where('status', QueueStatus::WithDoctor)->count());
    }

    public function test_a_patient_is_with_one_doctor_per_date(): void
    {
        $patient = Patient::factory()->create();
        QueueEntry::factory()->withDoctor()->create(['patient_id' => $patient->id]);

        $this->expectException(UniqueConstraintViolationException::class);

        QueueEntry::factory()->withDoctor()->create(['patient_id' => $patient->id]);
    }

    public function test_the_with_doctor_flag_follows_the_status(): void
    {
        $entry = QueueEntry::factory()->withDoctor()->create();

        $this->assertSame(1, $this->flag($entry));

        $entry->update(['status' => QueueStatus::Seen]);

        $this->assertNull($this->flag($entry));
    }

    public function test_deleting_a_referenced_doctor_is_refused(): void
    {
        $entry = QueueEntry::factory()->create();

        $this->expectException(QueryException::class);

        Doctor::query()->whereKey($entry->doctor_id)->delete();
    }

    public function test_deleting_a_referenced_patient_is_refused(): void
    {
        $entry = QueueEntry::factory()->create();

        $this->expectException(QueryException::class);

        Patient::query()->whereKey($entry->patient_id)->delete();
    }

    public function test_deleting_a_referenced_appointment_is_refused(): void
    {
        $entry = QueueEntry::factory()->forAppointment(Appointment::factory()->create())->create();

        $this->expectException(QueryException::class);

        Appointment::query()->whereKey($entry->appointment_id)->delete();
    }

    public function test_deleting_the_user_who_checked_in_is_refused(): void
    {
        $entry = QueueEntry::factory()->create();

        $this->expectException(QueryException::class);

        User::query()->whereKey($entry->checked_in_by)->delete();
    }

    public function test_a_queue_day_is_unique_per_doctor_and_date(): void
    {
        $doctor = Doctor::factory()->create();
        QueueDay::query()->create(['doctor_id' => $doctor->id, 'date' => '2026-11-01']);

        $this->expectException(UniqueConstraintViolationException::class);

        QueueDay::query()->create(['doctor_id' => $doctor->id, 'date' => '2026-11-01']);
    }

    private function flag(QueueEntry $entry): ?int
    {
        return DB::table('queue_entries')->where('id', $entry->id)->value('with_doctor_flag');
    }
}
