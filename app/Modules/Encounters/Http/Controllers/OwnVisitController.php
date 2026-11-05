<?php

namespace App\Modules\Encounters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounters\Http\Resources\EncounterResource;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Services\EncounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * A signed-in doctor's visits and starting a visit's record (US1, US3). The doctor is resolved from
 * the account; a doctor without a profile gets 404.
 */
class OwnVisitController extends Controller
{
    public function __construct(
        private readonly EncounterService $encounters,
    ) {}

    public function start(Request $request, int $visit): JsonResponse
    {
        Gate::authorize('access', Encounter::class);

        return EncounterResource::make($this->encounters->start($request->user(), $visit))
            ->response()
            ->setStatusCode(201);
    }
}
