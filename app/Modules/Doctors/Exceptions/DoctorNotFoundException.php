<?php

namespace App\Modules\Doctors\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No doctor with the requested id, or the signed-in doctor has no profile yet (FR-016, FR-017).
 */
class DoctorNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('doctors::messages.doctor_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
