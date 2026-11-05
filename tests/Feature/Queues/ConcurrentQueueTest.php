<?php

namespace Tests\Feature\Queues;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueDay;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Scheduling\Support\ClockTime;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Simultaneous queue changes stay consistent (SC-002, research R3, R9). Real PHP processes act at
 * the same moment; their data must be committed, so this test truncates instead of rolling back,
 * and uses the real clock (child processes do not see travelTo).
 */
#[Group('concurrency')]
class ConcurrentQueueTest extends TestCase
{
    use DatabaseTruncation;

    private const PROCESSES = 8;

    /**
     * Committed rows would otherwise be seen by later tests that use RefreshDatabase.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_simultaneous_check_ins_get_numbers_one_to_n(): void
    {
        $doctor = Doctor::factory()->create();
        $receptionist = User::factory()->receptionist()->create();
        $appointments = collect(range(0, self::PROCESSES - 1))->map(fn (int $i) => Appointment::factory()
            ->at(ClinicClock::today(), ClockTime::fromMinutes(540 + $i * 20))
            ->create(['doctor_id' => $doctor->id]));

        $results = $this->runAtOnce($appointments->map(fn (Appointment $a) => ['check-in', $receptionist->id, $a->id])->all());

        $numbers = array_column($results, 'queue_number');
        sort($numbers);

        $this->assertSame(array_fill(0, self::PROCESSES, 'ok'), array_column($results, 'result'));
        $this->assertSame(range(1, self::PROCESSES), $numbers);
        $this->assertSame(self::PROCESSES, QueueDay::query()->sole()->last_number);
        $this->assertSame(self::PROCESSES, Appointment::query()->where('status', AppointmentStatus::CheckedIn)->count());
    }

    public function test_simultaneous_check_ins_of_one_appointment_add_one_entry(): void
    {
        $receptionist = User::factory()->receptionist()->create();
        $appointment = Appointment::factory()->at(ClinicClock::today(), '09:00')->create();

        $results = $this->runAtOnce(array_fill(0, self::PROCESSES, ['check-in', $receptionist->id, $appointment->id]));

        $this->assertCount(1, array_filter($results, fn (array $r) => $r['result'] === 'ok'));

        $refused = array_values(array_filter($results, fn (array $r) => $r['result'] === 'refused'));
        $this->assertCount(self::PROCESSES - 1, $refused);

        foreach ($refused as $result) {
            $this->assertArrayHasKey('appointment', $result['errors']);
        }

        $this->assertSame(1, QueueEntry::query()->count());
    }

    public function test_simultaneous_calls_of_one_doctor_call_one_patient(): void
    {
        $doctor = Doctor::factory()->create();
        QueueDay::query()->create(['doctor_id' => $doctor->id, 'date' => ClinicClock::today(), 'last_number' => 4]);

        // Number 2 is first in queue order, so the numbers alone cannot pick the right patient.
        $first = null;

        foreach ([1 => 600, 2 => 540, 3 => 560, 4 => 580] as $number => $minute) {
            $entry = QueueEntry::factory()->number($number)->create(['doctor_id' => $doctor->id, 'order_minute' => $minute]);
            $first = $number === 2 ? $entry : $first;
        }

        $results = $this->runAtOnce(array_fill(0, 2, ['call-next', $doctor->user_id, 0]));

        $this->assertCount(1, array_filter($results, fn (array $r) => $r['result'] === 'ok'));

        $refused = array_values(array_filter($results, fn (array $r) => $r['result'] === 'refused'));
        $this->assertCount(1, $refused);
        $this->assertSame(__('queues::messages.visit_in_progress'), $refused[0]['errors']['queue'][0]);

        $this->assertSame($first->id, QueueEntry::query()->where('status', QueueStatus::WithDoctor)->sole()->id);
    }

    /**
     * Start one process per [action, actorId, targetId], all acting at the same moment. Any process
     * that hit an unexpected error fails the test with its message.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $actions
     * @return list<array<string, mixed>>
     */
    private function runAtOnce(array $actions): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 1.5);

        $results = Process::pool(function (Pool $pool) use ($actions, $startAt) {
            foreach ($actions as [$action, $actorId, $targetId]) {
                $pool->path(base_path())
                    ->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'clinic_testing'])
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Support/queue-action.php', $action, (string) $actorId, (string) $targetId, $startAt]);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());

            $line = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);

            if ($line['result'] === 'error') {
                $this->fail("A queue process failed: {$line['class']}: {$line['message']}");
            }

            return $line;
        })->values()->all();
    }
}
