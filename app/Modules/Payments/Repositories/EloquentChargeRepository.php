<?php

namespace App\Modules\Payments\Repositories;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Repositories\Contracts\ChargeRepository;

class EloquentChargeRepository implements ChargeRepository
{
    public function find(int $id): ?Charge
    {
        return Charge::query()
            ->with(['doctor.user', 'patient', 'chargedBy', 'discountedBy', 'cancelledBy', 'payments.receivedBy', 'refunds.refundedBy'])
            ->find($id);
    }

    public function lockById(int $id): ?Charge
    {
        return Charge::query()->lockForUpdate()->find($id);
    }

    public function create(array $attributes): Charge
    {
        return Charge::query()->create($attributes);
    }

    public function update(Charge $charge, array $attributes): Charge
    {
        $charge->update($attributes);

        return $charge;
    }

    public function activeExistsForVisit(int $queueEntryId): bool
    {
        return Charge::query()->where('queue_entry_id', $queueEntryId)->where('active_flag', 1)->exists();
    }

    public function addPayment(Charge $charge, string $amount, PaymentMethod $method, int $userId): Payment
    {
        return $charge->payments()->create(['amount' => $amount, 'method' => $method, 'received_by' => $userId]);
    }
}
