<?php

namespace App\Modules\Encounters\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Encounters\Exceptions\EncounterNotFoundException;
use App\Modules\Encounters\Http\Requests\AddAddendumRequest;
use App\Modules\Encounters\Http\Requests\SaveEncounterRequest;
use App\Modules\Encounters\Http\Resources\AddendumResource;
use App\Modules\Encounters\Http\Resources\EncounterResource;
use App\Modules\Encounters\Models\Encounter;
use App\Modules\Encounters\Services\EncounterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * One medical record: open, save, finalize, add an addendum (US1, US2, US4, US5). Non-doctors get 403
 * before any lookup; a record the doctor may not read gets the same 404 as an unknown id, and a
 * doctor changing a record they may only read gets 403 (research R4).
 */
class EncounterController extends Controller
{
    public function __construct(
        private readonly EncounterService $encounters,
    ) {}

    public function show(int $encounter): EncounterResource
    {
        Gate::authorize('access', Encounter::class);

        return EncounterResource::make($this->readable($encounter));
    }

    public function update(SaveEncounterRequest $request, int $encounter): EncounterResource
    {
        Gate::authorize('write', $this->readable($encounter));

        return EncounterResource::make($this->encounters->save($encounter, $request->content()));
    }

    public function finalize(int $encounter): EncounterResource
    {
        Gate::authorize('access', Encounter::class);
        Gate::authorize('write', $this->readable($encounter));

        return EncounterResource::make($this->encounters->finalize($encounter));
    }

    public function addAddendum(AddAddendumRequest $request, int $encounter): JsonResponse
    {
        $found = $this->readable($encounter);

        Gate::authorize('write', $found);

        return AddendumResource::make($this->encounters->addAddendum($found, $request->validated('text')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Not Response::denyAsNotFound: with debug on, that response gains exception keys and would
     * differ from an unknown id's (research R4).
     *
     * @throws EncounterNotFoundException
     */
    private function readable(int $id): Encounter
    {
        $encounter = $this->encounters->find($id);

        if (Gate::denies('view', $encounter)) {
            throw new EncounterNotFoundException;
        }

        return $encounter;
    }
}
