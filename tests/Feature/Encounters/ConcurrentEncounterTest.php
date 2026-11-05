<?php

namespace Tests\Feature\Encounters;

use App\Modules\Doctors\Models\Doctor;
use App\Modules\Encounters\Enums\EncounterStatus;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Simultaneous record changes stay consistent (SC-004, research R3, R11). Real PHP processes act at
 * the same moment; their data must be committed, so this test truncates instead of rolling back, and
 * uses the real clock (child processes do not see travelTo).
 */
#[Group('concurrency')]
class ConcurrentEncounterTest extends TestCase
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

    public function test_simultaneous_starts_of_one_visit_create_one_record(): void
    {
        $doctor = Doctor::factory()->create();
        $entry = QueueEntry::factory()->seen()->create(['doctor_id' => $doctor->id]);

        $results = $this->runAtOnce(array_fill(0, self::PROCESSES, ['start', $doctor->user_id, $entry->id]));

        $this->assertCount(1, array_filter($results, fn (array $r) => $r['result'] === 'ok'));

        $refused = array_values(array_filter($results, fn (array $r) => $r['result'] === 'refused'));
        $this->assertCount(self::PROCESSES - 1, $refused);

        foreach ($refused as $result) {
            $this->assertArrayHasKey('visit', $result['errors']);
        }

        $this->assertSame(1, Encounter::query()->count());
    }

    /**
     * A save either lands before the finalize or is refused; the finalized content never changes
     * (US2-4, FR-008). Repeated because the order differs between runs.
     */
    public function test_saves_against_a_finalize_never_change_finalized_content(): void
    {
        $doctor = Doctor::factory()->create();

        foreach (range(1, 5) as $round) {
            $entry = QueueEntry::factory()->number($round)->seen()->create(['doctor_id' => $doctor->id]);
            $encounter = Encounter::factory()->forEntry($entry)->complete()->create(['chief_complaint' => 'BASE']);

            $actions = [['finalize', $doctor->user_id, $encounter->id]];

            foreach (range(1, 6) as $save) {
                $actions[] = ['save', $doctor->user_id, $encounter->id, "SAVE-{$save}"];
            }

            $results = $this->runAtOnce($actions);

            $this->assertSame('ok', $results[0]['result']);

            $allowed = ['BASE'];

            foreach (array_slice($results, 1) as $index => $result) {
                if ($result['result'] === 'ok') {
                    $allowed[] = 'SAVE-'.($index + 1);
                } else {
                    $this->assertArrayHasKey('encounter', $result['errors']);
                }
            }

            $final = $encounter->fresh();
            $this->assertSame(EncounterStatus::Finalized, $final->status);
            $this->assertContains($final->chief_complaint, $allowed);

            $after = $this->runAtOnce([['save', $doctor->user_id, $encounter->id, 'TOO-LATE']]);

            $this->assertSame('refused', $after[0]['result']);
            $this->assertSame($final->chief_complaint, $encounter->fresh()->chief_complaint);
        }
    }

    /**
     * Start one process per [action, actorId, targetId, text?], all acting at the same moment. Any
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
                    ->command([PHP_BINARY, 'tests/Support/encounter-action.php', $action[0], (string) $action[1], (string) $action[2], $startAt, $action[3] ?? '']);
            }
        })->start()->wait();

        return $results->collect()->map(function ($result) {
            $this->assertSame(0, $result->exitCode(), $result->errorOutput());

            $line = json_decode(trim($result->output()), true, flags: JSON_THROW_ON_ERROR);

            if ($line['result'] === 'error') {
                $this->fail("An encounter process failed: {$line['class']}");
            }

            return $line;
        })->values()->all();
    }
}
