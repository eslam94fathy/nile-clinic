<?php

namespace App\Modules\Doctors\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No specialty with the requested id.
 */
class SpecialtyNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('doctors::messages.specialty_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
