<?php

namespace App\Modules\Appointments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Appointments\Http\Requests\CancelAppointmentRequest;
use App\Modules\Appointments\Http\Requests\RescheduleAppointmentRequest;
use App\Modules\Appointments\Http\Resources\AppointmentResource;
use App\Modules\Appointments\Models\Appointment;
use App\Modules\Appointments\Services\AppointmentService;
use App\Modules\Appointments\Services\BookingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Administrators and receptionists reschedule and cancel appointments (US3) and record no-shows
 * (US4).
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

    public function noShow(Request $request, int $appointment): AppointmentResource
    {
        Gate::authorize('update', Appointment::class);

        return AppointmentResource::make($this->appointments->markNoShow($request->user(), $appointment));
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
