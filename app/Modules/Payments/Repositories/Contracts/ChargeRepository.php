<?php

namespace App\Modules\Payments\Repositories\Contracts;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;

interface ChargeRepository
{
    /**
     * With doctor and its account, patient, the staff members, and payments and refunds with theirs.
     */
    public function find(int $id): ?Charge;

    /**
     * The charge locked `FOR UPDATE` until the caller's transaction ends, or null. No relations are
     * loaded.
     */
    public function lockById(int $id): ?Charge;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Charge;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Charge $charge, array $attributes): Charge;

    /**
     * Whether the visit has a charge that is not cancelled. A plain read: call only with the queue
     * entry locked (research R5).
     */
    public function activeExistsForVisit(int $queueEntryId): bool;

    /**
     * Payments are only ever added: no method changes or deletes one (FR-012).
     */
    public function addPayment(Charge $charge, string $amount, PaymentMethod $method, int $userId): Payment;
}
