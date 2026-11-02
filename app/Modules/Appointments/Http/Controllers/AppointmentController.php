<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Http\Requests\BookAppointmentRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Services\BookingService;
use Illuminate\Http\JsonResponse;

/**
 * Administrators and receptionists book (US1), list, and view (US2) appointments.
 */
class AppointmentController extends Controller
{
    public function __construct(
        private readonly BookingService $booking,
    ) {}

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
