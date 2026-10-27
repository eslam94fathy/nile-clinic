<?php

namespace App\Modules\Staff\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * The change would leave the clinic without an active administrator (FR-021).
 */
class LastAdministratorException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('staff::messages.last_administrator')], 422);
    }

    /**
     * A refused request, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
