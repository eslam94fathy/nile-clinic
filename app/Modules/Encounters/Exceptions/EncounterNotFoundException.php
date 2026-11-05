<?php

namespace App\Modules\Encounters\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No record with the requested id, or one the doctor may not read: the two responses are identical,
 * so a doctor cannot learn whether a record exists (FR-013, research R4).
 */
class EncounterNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('encounters::messages.encounter_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
