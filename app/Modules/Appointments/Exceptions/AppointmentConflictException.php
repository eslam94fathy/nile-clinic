<?php

namespace App\Modules\Appointments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The requested time is taken (research R6): by the doctor ("slot_booked") or by the patient
 * ("patient_busy"). The reason is a fixed identifier in every locale (FR-021).
 */
class AppointmentConflictException extends RuntimeException
{
    /**
     * @param  'slot_booked'|'patient_busy'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __("appointments::messages.{$this->reason}"),
            'reason' => $this->reason,
        ], 409);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
