<?php

namespace App\Modules\Queues\Repositories\Contracts;

/**
 * Whether a visit has been charged (feature 009, research R4, approved D4). Declared by Queues,
 * implemented and bound by the Payments module, so the dependency goes one way: Payments → Queues.
 */
interface VisitChargeRepository
{
    /**
     * Whether the visit has a charge that is not cancelled. A plain read: call only with the queue
     * entry locked, as the transaction's first plain read.
     */
    public function hasActiveCharge(int $queueEntryId): bool;
}
