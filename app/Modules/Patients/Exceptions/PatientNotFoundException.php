<?php

namespace App\Modules\Patients\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No patient with the requested patient number (FR-013).
 */
class PatientNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('patients::messages.not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
