<?php

namespace App\Modules\System\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializes the health check result (contracts/health.openapi.yaml).
 */
class HealthResource extends JsonResource
{
    /**
     * No "data" wrapper: the contract's top level is the health object itself.
     *
     * @var string|null
     */
    public static $wrap = null;

    /**
     * @return array{status: string, checks: array{database: string}}
     */
    public function toArray(Request $request): array
    {
        return [
            'status' => $this->resource['status'],
            'checks' => [
                'database' => $this->resource['checks']['database'],
            ],
        ];
    }
}
