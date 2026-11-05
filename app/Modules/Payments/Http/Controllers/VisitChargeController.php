<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Http\Resources\ChargeResource;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Services\ChargeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Visits and their charges (US2, US3). Doctors get 403 before any lookup.
 */
class VisitChargeController extends Controller
{
    public function __construct(
        private readonly ChargeService $charges,
    ) {}

    public function store(Request $request, int $visit): JsonResponse
    {
        Gate::authorize('create', Charge::class);

        return ChargeResource::make($this->charges->charge($request->user(), $visit))
            ->response()
            ->setStatusCode(201);
    }
}
