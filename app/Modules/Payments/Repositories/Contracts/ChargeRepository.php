<?php

namespace App\Modules\Payments\Repositories\Contracts;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Models\Refund;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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

    /**
     * Refunds are only ever added: no method changes or deletes one (FR-012).
     */
    public function addRefund(Charge $charge, string $amount, PaymentMethod $method, string $reason, int $userId): Refund;

    /**
     * One page of a date's visits, by doctor, then queue order, with patient and doctor account loaded,
     * and `charge_id`, `charge_status`, `charge_fee`, `charge_discount`, and `charge_amount_paid`
     * selected from the active charge, if any. A left visit is listed only with an active charge
     * (research R8).
     *
     * @param  array{doctor_id?: int, charge_status?: string}  $filters
     * @return LengthAwarePaginator<int, QueueEntry>
     */
    public function paginateVisits(string $date, array $filters, int $perPage): LengthAwarePaginator;

    /**
     * One page of charges for a date range, newest first, with patient and doctor account loaded.
     *
     * @param  array{from: string, to: string, patient_id?: int, doctor_id?: int, status?: string}  $filters
     * @return LengthAwarePaginator<int, Charge>
     */
    public function paginate(array $filters, int $perPage): LengthAwarePaginator;
}
