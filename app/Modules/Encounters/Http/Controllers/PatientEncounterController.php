<?php

namespace App\Modules\Encounters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounters\Http\Requests\ListPatientEncountersRequest;
use App\Modules\Encounters\Http\Resources\EncounterHistoryItemResource;
use App\Modules\Encounters\Services\EncounterService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A patient's history for a signed-in doctor (US4). Nothing readable gets the same 404 as an unknown
 * patient (FR-013).
 */
class PatientEncounterController extends Controller
{
    public function __construct(
        private readonly EncounterService $encounters,
    ) {}

    public function index(ListPatientEncountersRequest $request, int $patient): AnonymousResourceCollection
    {
        return EncounterHistoryItemResource::collection(
            $this->encounters->history($request->user(), $patient, $request->perPage()),
        );
    }
}
