<?php

namespace Tests\Feature\Payments;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Models\Payment;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Simultaneous money changes stay consistent (SC-002, research R3, R11). Real PHP processes act at the
 * same moment; their data must be committed, so this test truncates instead of rolling back, and uses
 * the real clock (child processes do not see travelTo).
 */
#[Group('concurrency')]
class ConcurrentPaymentTest extends TestCase
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

    public function test_simultaneous_charges_of_one_visit_create_one_charge(): void
    {
        $doctor = Doctor::factory()->create();
        DoctorFee::factory()->create(['doctor_id' => $doctor->id]);
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]);
        $actor = User::factory()->receptionist()->create();

        $results = $this->runAtOnce(array_fill(0, self::PROCESSES, ['charge', $actor->id, $entry->id]));

        $this->assertCount(1, array_filter($results, fn (array $r) => $r['result'] === 'ok'));

        $refused = array_values(array_filter($results, fn (array $r) => $r['result'] === 'refused'));
        $this->assertCount(self::PROCESSES - 1, $refused);

        foreach ($refused as $result) {
            $this->assertArrayHasKey('visit', $result['errors']);
        }

        $this->assertSame(1, Charge::query()->count());
    }

    public function test_simultaneous_payments_never_exceed_the_balance(): void
    {
        $charge = Charge::factory()->fee('100.00')->create();
        $actor = User::factory()->receptionist()->create();

        $results = $this->runAtOnce(array_fill(0, self::PROCESSES, ['pay', $actor->id, $charge->id, '30.00']));

        $this->assertCount(3, array_filter($results, fn (array $r) => $r['result'] === 'ok'));

        $refused = array_values(array_filter($results, fn (array $r) => $r['result'] === 'refused'));
        $this->assertCount(5, $refused);

        foreach ($refused as $result) {
            $this->assertArrayHasKey('amount', $result['errors']);
        }

        $this->assertSame('90.00', $charge->fresh()->amount_paid);
        $this->assertSame(3, Payment::query()->count());
        $this->assertSame('90.00', Payment::query()->sum('amount'));
    }

    /**
     * Start one process per [action, actorId, targetId, amount?], all acting at the same moment. Any
     * process that hit an unexpected error fails the test with its class.
     *
     * @param  list<array{0: string, 1: int, 2: int, 3?: string}>  $actions
     * @return list<array<string, mixed>>
     */
    private function runAtOnce(array $actions): array
    {
        $startAt = sprintf('%.6F', microtime(true) + 1.5);

        $results = Process::pool(function (Pool $pool) use ($actions, $startAt) {
            foreach ($actions as $action) {
                $pool->path(base_path())
                    ->env(['APP_ENV' => 'testing', 'DB_DATABASE' => 'clinic_testing'])
                    ->timeout(60)
                    ->command([PHP_BINARY, 'tests/Support/payment-action.php', $action[0], (string) $action[1], (string) $action[2], $startAt, $action[3] ?? '']);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());

            $line = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);

            if ($line['result'] === 'error') {
                $this->fail("A payment process failed: {$line['class']}");
            }

            return $line;
        })->values()->all();
    }
}
