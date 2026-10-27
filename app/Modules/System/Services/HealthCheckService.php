<?php

namespace App\Modules\System\Services;

use App\Modules\System\Repositories\Contracts\DatabaseStatusRepository;

class HealthCheckService
{
    public function __construct(
        private readonly DatabaseStatusRepository $databaseStatus,
    ) {}

    /**
     * Build the health status reported by GET /api/v1/health.
     *
     * @return array{status: 'ok'|'unavailable', checks: array{database: 'ok'|'unavailable'}}
     */
    public function check(): array
    {
        $database = $this->databaseStatus->isReachable() ? 'ok' : 'unavailable';

        return [
            'status' => $database,
            'checks' => [
                'database' => $database,
            ],
        ];
    }
}
