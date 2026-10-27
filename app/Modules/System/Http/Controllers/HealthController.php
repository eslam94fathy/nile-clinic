<?php

namespace App\Modules\System\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\System\Http\Resources\HealthResource;
use App\Modules\System\Services\HealthCheckService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * GET /api/v1/health — public by design (clarification Q2); no input, so no Form Request or Policy.
 */
class HealthController extends Controller
{
    public function __invoke(HealthCheckService $service): JsonResponse
    {
        $health = $service->check();

        return HealthResource::make($health)
            ->response()
            ->setStatusCode($health['status'] === 'ok'
                ? Response::HTTP_OK
                : Response::HTTP_SERVICE_UNAVAILABLE);
    }
}
