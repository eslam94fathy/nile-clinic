<?php

namespace Tests\Feature\System;

use App\Modules\System\Repositories\Contracts\DatabaseStatusRepository;
use Tests\TestCase;

/**
 * GET /api/v1/health (FR-020, contracts/health.openapi.yaml).
 */
class HealthEndpointTest extends TestCase
{
    public function test_reports_ok_when_the_database_is_reachable(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok'],
            ]);
    }

    public function test_reports_unavailable_without_internal_details_when_the_database_is_unreachable(): void
    {
        $this->app->instance(DatabaseStatusRepository::class, new class implements DatabaseStatusRepository
        {
            public function isReachable(): bool
            {
                return false;
            }
        });

        $this->getJson('/api/v1/health')
            ->assertStatus(503)
            ->assertExactJson([
                'status' => 'unavailable',
                'checks' => ['database' => 'unavailable'],
            ]);
    }

    public function test_responds_with_json_even_without_a_json_accept_header(): void
    {
        $this->get('/api/v1/health')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_body_is_identical_for_arabic_requests(): void
    {
        $this->getJson('/api/v1/health', ['Accept-Language' => 'ar'])
            ->assertOk()
            ->assertExactJson([
                'status' => 'ok',
                'checks' => ['database' => 'ok'],
            ]);
    }
}
