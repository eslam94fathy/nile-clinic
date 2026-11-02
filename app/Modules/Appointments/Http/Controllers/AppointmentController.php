<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Http\Requests\BookAppointmentRequest;
use App\Modules\Appointments\Http\Requests\ListAppointmentsRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Http\Resources\AppointmentSummaryResource;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\BookingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators and receptionists book (US1), list, and view (US2) appointments.
 */
class AppointmentController extends Controller
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
    ) {}

    public function index(ListAppointmentsRequest $request): AnonymousResourceCollection
    {
        return AppointmentSummaryResource::collection(
            $this->appointments->list($request->filters(), $request->perPage()),
        );
    }

    public function show(int $appointment): AppointmentResource
    {
        Gate::authorize('view', Appointment::class);

        return AppointmentResource::make($this->appointments->find($appointment));
    }

    /**
     * The service returns the appointment reloaded with its relations, so 201 is set explicitly.
     */
    public function store(BookAppointmentRequest $request): JsonResponse
    {
        return AppointmentResource::make($this->booking->book($request->user(), $request->validated()))
            ->response()
            ->setStatusCode(201);
    }
}
