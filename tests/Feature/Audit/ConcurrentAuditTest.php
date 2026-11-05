<?php

namespace Tests\Feature\Audit;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Audit\Enums\AuditAction;
use App\Modules\Audit\Models\AuditEntry;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Patients\Models\Patient;
use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Entries take shared locks on the acting user's row and the patient's row, in transactions that took
 * none before (research R10). Real processes with a real signed-in actor act at the same moment, on the
 * same rows: none may deadlock or time out, each success has exactly one entry, and each refusal none.
 * Their data must be committed, so this test truncates, and uses the real clock.
 */
#[Group('concurrency')]
class ConcurrentAuditTest extends TestCase
{
    use DatabaseTruncation;

    private const ROUNDS = 4;

    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_deactivating_a_staff_member_while_they_set_discounts(): void
    {
        $administrator = User::factory()->administrator()->create();

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $member = User::factory()->administrator()->create();
            $actions = [['deactivate', $administrator->id, $member->id]];

            for ($process = 0; $process < 7; $process++) {
                $actions[] = ['discount', $member->id, Charge::factory()->fee('300.00')->create()->id];
            }

            $this->assertEntriesMatch($this->runAtOnce($actions), [
                'deactivate' => AuditAction::StaffDeactivated,
                'discount' => AuditAction::DiscountSet,
            ]);

            $this->assertFalse($member->fresh()->is_active);
        }
    }

    public function test_saving_a_record_while_the_patient_is_rescheduled_and_called_by_another_doctor(): void
    {
        $tomorrow = ClinicClock::now()->addDay();
        $reception = User::factory()->receptionist()->create();

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $patient = Patient::factory()->create();

            $author = Doctor::factory()->create();
            $visit = QueueEntry::factory()->seen()->create(['doctor_id' => $author->id, 'patient_id' => $patient->id]);
            $encounter = Encounter::factory()->forEntry($visit)->create();

            $caller = Doctor::factory()->create();
            $waiting = QueueEntry::factory()->create(['doctor_id' => $caller->id, 'patient_id' => $patient->id]);

            $booked = Doctor::factory()->create();
            DoctorSchedule::factory()
                ->withPeriod(strtolower($tomorrow->englishDayOfWeek), '09:00', '11:00')
                ->create(['doctor_id' => $booked->id]);
            $appointment = Appointment::factory()->at($tomorrow->toDateString(), '09:00')->create(['doctor_id' => $booked->id, 'patient_id' => $patient->id]);

            $this->assertEntriesMatch($this->runAtOnce([
                ['save', $author->user_id, $encounter->id],
                ['reschedule', $reception->id, $appointment->id, $tomorrow->toDateString().',10:00'],
                ['call-next', $caller->user_id, 0],
            ]), [
                'save' => AuditAction::EncounterDraftSaved,
                'reschedule' => AuditAction::AppointmentRescheduled,
                'call-next' => AuditAction::VisitCalled,
            ]);

            $this->assertSame(QueueStatus::WithDoctor, $waiting->fresh()->status);
        }
    }

    public function test_a_cancellation_and_a_check_in_on_the_same_patients_appointments(): void
    {
        $today = ClinicClock::today();
        $tomorrow = ClinicClock::now()->addDay()->toDateString();
        $reception = User::factory()->receptionist()->create();

        for ($round = 0; $round < self::ROUNDS; $round++) {
            $patient = Patient::factory()->create();
            $toCheckIn = Appointment::factory()->at($today, '09:00')->create(['patient_id' => $patient->id]);
            $toCancel = Appointment::factory()->at($tomorrow, '09:00')->create(['patient_id' => $patient->id]);
            $alsoToCancel = Appointment::factory()->at($tomorrow, '10:00')->create(['patient_id' => $patient->id]);

            $this->assertEntriesMatch($this->runAtOnce([
                ['check-in', $reception->id, $toCheckIn->id],
                ['cancel', $reception->id, $toCancel->id],
                ['cancel', $reception->id, $alsoToCancel->id],
            ]), [
                'check-in' => AuditAction::VisitCheckedIn,
                'cancel' => AuditAction::AppointmentCancelled,
            ]);

            $this->assertSame(AppointmentStatus::CheckedIn, $toCheckIn->fresh()->status);
            $this->assertSame(AppointmentStatus::Cancelled, $toCancel->fresh()->status);
        }
    }

    /**
     * Each success has exactly one entry, and each refusal none. Entries from earlier rounds are cleared
     * here, so that each round's count stands alone.
     *
     * @param  list<array{result: string, action: string}>  $results
     * @param  array<string, AuditAction>  $actions
     */
    private function assertEntriesMatch(array $results, array $actions): void
    {
        foreach ($actions as $name => $action) {
            $succeeded = count(array_filter($results, fn (array $r) => $r['action'] === $name && $r['result'] === 'ok'));

            $this->assertGreaterThan(0, $succeeded, "No {$name} succeeded.");
            $this->assertSame($succeeded, AuditEntry::query()->where('action', $action)->count(), "{$name}: one entry per success.");
            $this->assertSame(0, AuditEntry::query()->where('action', $action)->whereNull('actor_id')->count(), "{$name}: the user row must be exercised.");

            AuditEntry::query()->where('action', $action)->delete();
        }
    }

    /**
     * @param  list<array{0: string, 1: int, 2: int, 3?: string}>  $actions
     * @return list<array{result: string, action: string}>
     */
    private function runAtOnce(array $actions): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 1.5);

        $results = Process::pool(function (Pool $pool) use ($actions, $startAt) {
            foreach ($actions as $action) {
                $pool->path(base_path())
                    ->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'clinic_testing'])
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Support/audit-action.php', $action[0], (string) $action[1], (string) $action[2], $startAt, $action[3] ?? '']);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());

            $line = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);

            if ($line['result'] === 'error') {
                $this->fail("{$line['action']} failed: {$line['class']}, SQLSTATE {$line['sqlstate']}.");
            }

            return $line;
        })->values()->all();
    }
}
