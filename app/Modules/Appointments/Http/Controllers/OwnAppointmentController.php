<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Http\Requests\ListOwnAppointmentsRequest;
use App\Modules\Appointments\Http\Resources\OwnAppointmentResource;
use App\Modules\Appointments\Http\Resources\OwnAppointmentSummaryResource;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * A signed-in doctor reads their own appointments (US5, FR-015). The doctor is resolved from the
 * account; a doctor without a profile gets 404, and so does another doctor's appointment.
 */
class OwnAppointmentController extends Controller
{
    public function __construct(
        private readonly AppointmentService $appointments,
    ) {}

    public function index(ListOwnAppointmentsRequest $request): AnonymousResourceCollection
    {
        return OwnAppointmentSummaryResource::collection(
            $this->appointments->listOwn($request->user(), $request->filters(), $request->perPage()),
        );
    }

    public function show(Request $request, int $appointment): OwnAppointmentResource
    {
        Gate::authorize('viewOwn', Appointment::class);

        return OwnAppointmentResource::make($this->appointments->findOwn($request->user(), $appointment));
    }
}
