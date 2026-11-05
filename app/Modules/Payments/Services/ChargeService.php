<?php

namespace App\Modules\Payments\Services;

use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Exceptions\ChargeNotFoundException;
use App\Modules\Payments\Exceptions\VisitNotFoundException;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Repositories\Contracts\ChargeRepository;
use App\Modules\Payments\Repositories\Contracts\DoctorFeeRepository;
use App\Modules\Payments\Support\Money;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Staff\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Charges and the money on them (FR-003–FR-018). Authorization is the policies'. Every change decides
 * from a locked row, so no decision is made from a plain read. All arithmetic is in whole piastres
 * through Money (research R2, R3).
 */
class ChargeService
{
    public function __construct(
        private readonly ChargeRepository $charges,
        private readonly DoctorFeeRepository $fees,
        private readonly QueueRepository $queue,
    ) {}

    /**
     * Charge a visit at the doctor's fee at this moment (FR-003–FR-006). The queue entry lock is the
     * transaction's first read, so a charge and an undone check-in run one after the other (research
     * R4, R5).
     *
     * @throws VisitNotFoundException
     * @throws ValidationException the patient left, the visit is already charged, or the doctor has no fee
     */
    public function charge(User $actor, int $visitId): Charge
    {
        $charge = DB::transaction(function () use ($actor, $visitId) {
            $entry = $this->queue->lockById($visitId) ?? throw new VisitNotFoundException;

            if ($entry->status === QueueStatus::Left) {
                throw $this->refusal('visit', 'visit_left');
            }

            if ($this->charges->activeExistsForVisit($entry->id)) {
                throw $this->refusal('visit', 'already_charged');
            }

            $fee = $this->fees->findForDoctor($entry->doctor_id) ?? throw $this->refusal('visit', 'no_fee');

            return $this->charges->create([
                'queue_entry_id' => $entry->id,
                'doctor_id' => $entry->doctor_id,
                'patient_id' => $entry->patient_id,
                'date' => $entry->date->toDateString(),
                'status' => ChargeStatus::Unpaid,
                'fee' => $fee->amount,
                'charged_by' => $actor->id,
            ]);
        }, attempts: 3);

        return $this->find($charge->id);
    }

    /**
     * A date's visits with their active charge, if any (FR-016).
     *
     * @param  array{doctor_id?: int, charge_status?: string}  $filters
     * @return LengthAwarePaginator<int, QueueEntry>
     */
    public function listVisits(string $date, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->charges->paginateVisits($date, $filters, $perPage);
    }

    /**
     * Charges for a date range, newest first (FR-017).
     *
     * @param  array{from: string, to: string, patient_id?: int, doctor_id?: int, status?: string}  $filters
     * @return LengthAwarePaginator<int, Charge>
     */
    public function list(array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->charges->paginate($filters, $perPage);
    }

    /**
     * @throws ChargeNotFoundException
     */
    public function find(int $id): Charge
    {
        return $this->charges->find($id) ?? throw new ChargeNotFoundException;
    }

    /**
     * Record money received, up to the balance, also under simultaneous requests (FR-008, FR-009).
     *
     * @throws ChargeNotFoundException
     * @throws ValidationException when cancelled, or the amount is above the balance
     */
    public function pay(User $actor, int $id, string $amount, PaymentMethod $method): Charge
    {
        DB::transaction(function () use ($actor, $id, $amount, $method) {
            $charge = $this->lockActive($id);
            $due = $this->duePiastres($charge);
            $paid = Money::toPiastres($charge->amount_paid);

            if (Money::toPiastres($amount) > $due - $paid) {
                throw $this->refusal('amount', 'above_balance');
            }

            $this->charges->addPayment($charge, $amount, $method, $actor->id);
            $this->applyPaid($charge, $paid + Money::toPiastres($amount), $due);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * Set or replace the discount, up to the fee less what is paid (FR-011). Zero removes it. The fee
     * itself never changes (FR-005).
     *
     * @throws ChargeNotFoundException
     * @throws ValidationException when cancelled, or the discount is too large
     */
    public function discount(User $actor, int $id, string $amount, string $reason): Charge
    {
        DB::transaction(function () use ($actor, $id, $amount, $reason) {
            $charge = $this->lockActive($id);
            $fee = Money::toPiastres($charge->fee);
            $paid = Money::toPiastres($charge->amount_paid);
            $discount = Money::toPiastres($amount);

            if ($discount > $fee - $paid) {
                throw $this->refusal('amount', 'discount_too_large');
            }

            $this->charges->update($charge, [
                'discount' => $amount,
                'discount_reason' => $reason,
                'discounted_by' => $actor->id,
                'discounted_at' => now(),
                'status' => $this->statusFor($fee - $discount, $paid),
            ]);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * Record money returned, up to what was paid, also under simultaneous requests (FR-015). What was
     * received stays on record (FR-012).
     *
     * @throws ChargeNotFoundException
     * @throws ValidationException when cancelled, or the amount is above what was paid
     */
    public function refund(User $actor, int $id, string $amount, PaymentMethod $method, string $reason): Charge
    {
        DB::transaction(function () use ($actor, $id, $amount, $method, $reason) {
            $charge = $this->lockActive($id);
            $paid = Money::toPiastres($charge->amount_paid);

            if (Money::toPiastres($amount) > $paid) {
                throw $this->refusal('amount', 'above_paid');
            }

            $this->charges->addRefund($charge, $amount, $method, $reason, $actor->id);
            $this->applyPaid($charge, $paid - Money::toPiastres($amount), $this->duePiastres($charge));
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * Cancel a charge with no money held. It stays on record, and the visit can be charged again
     * (FR-013).
     *
     * @throws ChargeNotFoundException
     * @throws ValidationException when already cancelled, or money is held
     */
    public function cancel(User $actor, int $id, string $reason): Charge
    {
        DB::transaction(function () use ($actor, $id, $reason) {
            $charge = $this->lockActive($id);

            if (Money::toPiastres($charge->amount_paid) > 0) {
                throw $this->refusal('charge', 'money_held');
            }

            $this->charges->update($charge, [
                'status' => ChargeStatus::Cancelled,
                'cancel_reason' => $reason,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ]);
        }, attempts: 3);

        return $this->find($id);
    }

    /**
     * @throws ChargeNotFoundException
     * @throws ValidationException when cancelled
     */
    private function lockActive(int $id): Charge
    {
        $charge = $this->charges->lockById($id) ?? throw new ChargeNotFoundException;

        if ($charge->status === ChargeStatus::Cancelled) {
            throw $this->refusal('charge', 'charge_cancelled');
        }

        return $charge;
    }

    private function duePiastres(Charge $charge): int
    {
        return Money::toPiastres($charge->fee) - Money::toPiastres($charge->discount);
    }

    private function applyPaid(Charge $charge, int $paid, int $due): void
    {
        $this->charges->update($charge, [
            'amount_paid' => Money::fromPiastres($paid),
            'status' => $this->statusFor($due, $paid),
        ]);
    }

    /**
     * The status always follows from the figures (FR-007, research R3).
     */
    private function statusFor(int $due, int $paid): ChargeStatus
    {
        return match (true) {
            $due - $paid === 0 => ChargeStatus::Paid,
            $paid === 0 => ChargeStatus::Unpaid,
            default => ChargeStatus::PartiallyPaid,
        };
    }

    private function refusal(string $field, string $message): ValidationException
    {
        return ValidationException::withMessages([$field => __("payments::messages.{$message}")]);
    }
}
