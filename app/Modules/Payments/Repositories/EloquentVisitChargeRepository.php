<?php

namespace App\Modules\Payments\Repositories;

use App\Modules\Payments\Models\Charge;
use App\Modules\Queues\Repositories\Contracts\VisitChargeRepository;

class EloquentVisitChargeRepository implements VisitChargeRepository
{
    public function hasActiveCharge(int $queueEntryId): bool
    {
        return Charge::query()->where('queue_entry_id', $queueEntryId)->where('active_flag', 1)->exists();
    }
}
