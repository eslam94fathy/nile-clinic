<?php

namespace App\Modules\Encounters\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No visit with the requested id, or another doctor's visit.
 */
class VisitNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('encounters::messages.visit_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
