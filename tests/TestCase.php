<?php

namespace Tests;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Database traits that migrate, truncate, or write to the database during test setup.
     */
    private const DATABASE_TRAITS = [
        RefreshDatabase::class,
        DatabaseMigrations::class,
        DatabaseTruncation::class,
        DatabaseTransactions::class,
    ];

    private const TESTING_DATABASE = 'clinic_testing';

    /**
     * Refuse to run database-trait setup against anything but the test database.
     *
     * This overrides the framework's setUpTraits() rather than the beforeRefreshingDatabase()
     * hook, because that hook is declared inside the RefreshDatabase trait and would win over
     * an inherited guard.
     */
    protected function setUpTraits()
    {
        $uses = $this->traitsUsedByTest ?? class_uses_recursive(static::class);

        if (array_intersect(self::DATABASE_TRAITS, array_keys($uses)) !== []) {
            $this->ensureTestingDatabase();
        }

        return parent::setUpTraits();
    }

    protected function ensureTestingDatabase(): void
    {
        $database = DB::connection()->getDatabaseName();

        if ($database !== self::TESTING_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run database tests against [%s]; expected [%s].',
                $database,
                self::TESTING_DATABASE,
            ));
        }
    }
}
