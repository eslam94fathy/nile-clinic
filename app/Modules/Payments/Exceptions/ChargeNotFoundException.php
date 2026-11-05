<?php

namespace App\Modules\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No charge with the requested id.
 */
class ChargeNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('payments::messages.charge_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
