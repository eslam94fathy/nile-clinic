<?php

namespace App\Modules\Scheduling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Scheduling\Http\Requests\ListTimeOffRequest;
use App\Modules\Scheduling\Http\Requests\StoreTimeOffRequest;
use App\Modules\Scheduling\Http\Resources\TimeOffResource;
use App\Modules\Scheduling\Models\DoctorTimeOff;
use App\Modules\Scheduling\Services\TimeOffService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Staff list a doctor's time off; administrators record and remove it (US3).
 */
class TimeOffController extends Controller
{
    public function __construct(
        private readonly TimeOffService $timeOff,
    ) {}

    public function index(ListTimeOffRequest $request, int $doctor): AnonymousResourceCollection
    {
        return TimeOffResource::collection($this->timeOff->list(
            $doctor,
            $request->validated('from'),
            $request->validated('to'),
        ));
    }

    public function store(StoreTimeOffRequest $request, int $doctor): TimeOffResource
    {
        return TimeOffResource::make($this->timeOff->record($doctor, $request->validated()));
    }

    public function destroy(int $doctor, int $timeOff): Response
    {
        Gate::authorize('delete', DoctorTimeOff::class);

        $this->timeOff->remove($doctor, $timeOff);

        return response()->noContent();
    }
}
