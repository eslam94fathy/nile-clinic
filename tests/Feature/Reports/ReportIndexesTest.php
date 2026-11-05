<?php

namespace Tests\Feature\Reports;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Reports filter these tables by the instant a record was made (research R3).
 */
class ReportIndexesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, string}>
     */
    public static function indexes(): array
    {
        return [
            'payments' => ['payments', 'payments_created_at_index'],
            'refunds' => ['refunds', 'refunds_created_at_index'],
            'patients' => ['patients', 'patients_created_at_index'],
        ];
    }

    #[DataProvider('indexes')]
    public function test_the_index_exists(string $table, string $index): void
    {
        $this->assertTrue(Schema::hasIndex($table, $index));
    }
}
