<?php

namespace App\Modules\Encounters\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Nothing the doctor may read for this patient. An unknown patient gets the same response, so a
 * doctor cannot learn whether a patient exists (FR-013, research R5).
 */
class HistoryNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('encounters::messages.history_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
