<?php

namespace Tests\Feature\Reports;

use App\Modules\Appointments\Models\Appointment;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Staff\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Requesting a report changes nothing and creates nothing (SC-007, FR-005).
 */
class ReportReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    private const PATHS = [
        '/api/v1/reports/money-collected?from=2026-11-01&to=2026-11-01',
        '/api/v1/reports/billing?from=2026-11-01&to=2026-11-01',
        '/api/v1/reports/activity?from=2026-11-01&to=2026-11-01',
        '/api/v1/reports/times?from=2026-11-01&to=2026-11-01',
    ];

    private const TABLES = [
        'users', 'patients', 'doctors', 'appointments', 'queue_entries', 'encounters', 'charges', 'payments', 'refunds',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 08:00', 'Africa/Cairo'));
        Sanctum::actingAs(User::factory()->administrator()->create());

        $visit = QueueEntry::factory()->seen()->create();
        $charge = Charge::factory()->forEntry($visit)->partiallyPaid('100.00')->create();

        Payment::factory()->create(['charge_id' => $charge->id]);
        Refund::factory()->create(['charge_id' => $charge->id]);
        Encounter::factory()->forEntry($visit)->create();
        Appointment::factory()->at('2026-11-01', '09:00')->create();
    }

    public function test_every_report_leaves_the_records_unchanged(): void
    {
        $before = $this->snapshot();

        $this->travel(1)->hours();

        foreach (self::PATHS as $path) {
            $this->getJson($path)->assertOk();
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_every_statement_is_a_select(): void
    {
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        foreach (self::PATHS as $path) {
            $this->getJson($path)->assertOk();
        }

        $this->assertNotEmpty($statements);

        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select', strtolower(ltrim($sql)), $sql);
        }
    }

    /**
     * @return array<string, array{int, string|null}>
     */
    private function snapshot(): array
    {
        $snapshot = [];

        foreach (self::TABLES as $table) {
            $column = Schema::hasColumn($table, 'updated_at') ? 'updated_at' : 'created_at';

            $snapshot[$table] = [DB::table($table)->count(), DB::table($table)->max($column)];
        }

        return $snapshot;
    }
}
