<?php

namespace Tests\Feature\Infrastructure;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use PDOException;
use Tests\TestCase;

/**
 * Database errors are logged without the values that were bound to the query, so patient
 * details never reach the log (approved D3, research R7, FR-017).
 */
class QueryErrorLoggingTest extends TestCase
{
    public function test_query_errors_are_logged_without_bound_values(): void
    {
        Log::spy();

        $sql = 'insert into patients (full_name, national_id) values (?, ?)';

        report(new QueryException(
            'mysql',
            $sql,
            ['Synthetic Name', '29001010100001'],
            new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '29001010100001'"),
        ));

        $calls = [];

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context = []) use (&$calls) {
                $calls[] = json_encode([$message, $context], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return true;
            });

        $logged = $calls[0];

        $this->assertStringContainsString($sql, $logged);
        $this->assertStringNotContainsString('Synthetic Name', $logged);
        $this->assertStringNotContainsString('29001010100001', $logged);
    }
}
