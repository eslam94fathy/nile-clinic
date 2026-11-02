<?php

namespace Tests\Feature\Appointments;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Doctors\Models\Doctor;
use App\Modules\Patients\Models\Patient;
use App\Modules\Scheduling\Models\DoctorSchedule;
use App\Modules\Scheduling\Support\ClinicClock;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Simultaneous bookings never double-book (SC-002, research R3, R9). Eight real PHP processes
 * book at the same moment; their data must be committed, so this test truncates instead of
 * rolling back, and uses the real clock (child processes do not see travelTo).
 */
#[Group('concurrency')]
class ConcurrentBookingTest extends TestCase
{
    use DatabaseTruncation;

    private const PROCESSES = 8;

    private User $receptionist;

    private string $date;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receptionist = User::factory()->receptionist()->create();

        // The next Sunday at least two days after the clinic's today, so the slot cannot start
        // while the test runs.
        $this->date = CarbonImmutable::parse(ClinicClock::today())->addDays(2)->next(CarbonImmutable::SUNDAY)->toDateString();
    }

    /**
     * Committed rows would otherwise be seen by later tests that use RefreshDatabase.
     */
    protected function tearDown(): void
    {
        $this->truncateTablesForAllConnections();

        parent::tearDown();
    }

    public function test_only_one_of_simultaneous_bookings_for_one_slot_succeeds(): void
    {
        $doctor = $this->doctorWithSundayHours();
        $patients = Patient::factory()->count(self::PROCESSES)->create();

        $results = $this->bookAtOnce($patients->map(fn (Patient $patient) => [$patient->id, $doctor->id])->all());

        $this->assertOutcome($results, 'slot_booked');
        $this->assertSame(1, Appointment::query()->where('doctor_id', $doctor->id)->where('status', AppointmentStatus::Scheduled)->count());
    }

    public function test_only_one_of_simultaneous_bookings_for_one_patient_succeeds(): void
    {
        $patient = Patient::factory()->create();
        $doctors = collect(range(1, self::PROCESSES))->map(fn () => $this->doctorWithSundayHours());

        $results = $this->bookAtOnce($doctors->map(fn (Doctor $doctor) => [$patient->id, $doctor->id])->all());

        $this->assertOutcome($results, 'patient_busy');
        $this->assertSame(1, Appointment::query()->where('patient_id', $patient->id)->where('status', AppointmentStatus::Scheduled)->count());
    }

    private function doctorWithSundayHours(): Doctor
    {
        $doctor = Doctor::factory()->create();
        DoctorSchedule::factory()->withPeriod('sunday', '09:00', '10:00')->create(['doctor_id' => $doctor->id]);

        return $doctor;
    }

    /**
     * Start one process per [patientId, doctorId] pair, all booking 09:20 at the same moment.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return list<array<string, mixed>>
     */
    private function bookAtOnce(array $pairs): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 1.5);

        $results = Process::pool(function (Pool $pool) use ($pairs, $startAt) {
            foreach ($pairs as [$patientId, $doctorId]) {
                $pool->path(base_path())
                    ->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'clinic_testing'])
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Support/book-appointment.php', (string) $this->receptionist->id, (string) $patientId, (string) $doctorId, $this->date, '09:20', $startAt]);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());

            return json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function assertOutcome(array $results, string $conflictReason): void
    {
        foreach ($results as $result) {
            if ($result['result'] === 'error') {
                $this->fail("A booking process failed: {$result['class']}: {$result['message']}");
            }
        }

        $this->assertCount(1, array_filter($results, fn (array $r) => $r['result'] === 'booked'));
        $this->assertSame(
            array_fill(0, self::PROCESSES - 1, $conflictReason),
            array_column(array_filter($results, fn (array $r) => $r['result'] === 'conflict'), 'reason'),
        );
    }
}
