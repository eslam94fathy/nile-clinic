<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Http\Requests\CancelAppointmentRequest;
use App\Modules\Appointments\Http\Requests\RescheduleAppointmentRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\BookingService;

/**
 * Administrators and receptionists reschedule and cancel appointments (US3).
 */
class AppointmentActionController extends Controller
{
    public function __construct(
        private readonly BookingService $booking,
        private readonly AppointmentService $appointments,
    ) {}

    public function reschedule(RescheduleAppointmentRequest $request, int $appointment): AppointmentResource
    {
        return AppointmentResource::make($this->booking->reschedule(
            $request->user(),
            $appointment,
            $request->validated('date'),
            $request->validated('start'),
        ));
    }

    public function cancel(CancelAppointmentRequest $request, int $appointment): AppointmentResource
    {
        return AppointmentResource::make($this->appointments->cancel(
            $request->user(),
            $appointment,
            $request->validated('reason'),
        ));
    }
}
