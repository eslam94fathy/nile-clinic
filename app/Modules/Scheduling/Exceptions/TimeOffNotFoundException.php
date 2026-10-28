<?php

namespace App\Modules\Scheduling\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No time off with the requested id for this doctor (FR-017).
 */
class TimeOffNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('scheduling::messages.time_off_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
