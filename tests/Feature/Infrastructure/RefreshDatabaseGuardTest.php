<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the guard runs on the real RefreshDatabase setup path, where a trait method
 * would otherwise take precedence over an inherited hook (analysis R1).
 */
class RefreshDatabaseGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_database_setup_refuses_a_non_testing_database(): void
    {
        $original = config('database.connections.mysql.database');
        config(['database.connections.mysql.database' => 'clinic']);
        DB::purge('mysql');

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to run database tests against [clinic]');

            $this->setUpTraits();
        } finally {
            config(['database.connections.mysql.database' => $original]);
            DB::purge('mysql');
        }
    }
}
