<?php

namespace App\Modules\Queues\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * No queue entry with the requested id.
 */
class QueueEntryNotFoundException extends RuntimeException
{
    public function render(): JsonResponse
    {
        return response()->json(['message' => __('queues::messages.entry_not_found')], 404);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
