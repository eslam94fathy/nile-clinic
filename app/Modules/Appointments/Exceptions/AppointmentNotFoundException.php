<?php

namespace App\Modules\Appointments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No appointment with the requested id, or not one of the signed-in doctor's own (research R6,
 * R8).
 */
class AppointmentNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('appointments::messages.not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
