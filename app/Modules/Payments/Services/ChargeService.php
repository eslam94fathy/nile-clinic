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
use App\Modules\Queues\Repositories\Contracts\QueueRepository;
use App\Modules\Staff\Models\User;
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
