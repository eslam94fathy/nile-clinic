<?php

namespace App\Modules\Payments\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No visit with the requested id.
 */
class VisitNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('payments::messages.visit_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
