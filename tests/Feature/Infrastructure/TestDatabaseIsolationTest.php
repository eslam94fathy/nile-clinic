<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Guards against the suite ever running against development data (FR-014, analysis C1/R1).
 * Deliberately does not use RefreshDatabase.
 */
class TestDatabaseIsolationTest extends TestCase
{
    public function test_suite_targets_the_mysql_testing_database(): void
    {
        $this->assertSame('mysql', config('database.default'));
        $this->assertSame('clinic_testing', DB::connection()->getDatabaseName());
    }

    public function test_guard_rejects_any_other_database(): void
    {
        $original = config('database.connections.mysql.database');
        config(['database.connections.mysql.database' => 'clinic']);
        DB::purge('mysql');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to run database tests against [clinic]');

            $this->ensureTestingDatabase();
        } finally {
            config(['database.connections.mysql.database' => $original]);
            DB::purge('mysql');
        }
    }
}
