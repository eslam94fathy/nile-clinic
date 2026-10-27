<?php

namespace App\Modules\Staff\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No staff account with the requested id (research R10).
 */
class StaffMemberNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('staff::messages.not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
