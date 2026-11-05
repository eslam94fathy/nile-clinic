<?php

namespace App\Modules\Queues\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A database backstop refused the change (research R3): the appointment already has an entry
 * ("already_checked_in"), or the patient is with another doctor ("patient_with_another_doctor").
 * The reason is a fixed identifier in every locale (FR-019).
 */
class QueueConflictException extends RuntimeException
{
    /**
     * @param  'already_checked_in'|'patient_with_another_doctor'  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => __("queues::messages.{$this->reason}"),
            'reason' => $this->reason,
        ], 409);
    }

    /**
     * A client error, not an application fault: never written to the log.
     */
    public function report(): void
    {
        //
    }
}
