<?php

namespace App\Modules\Reports\Repositories\Contracts;

/**
 * Read-only aggregates over other modules' records (research R2). Every method selects only the columns
 * listed in the data model, so no patient detail, reason, or clinical text can be read, and none of them
 * writes or locks. Amounts are exact decimal strings.
 */
interface ReportRepository
{
    /**
     * Payments between two UTC instants ($end excluded), per UTC hour, method, and staff member.
     *
     * @return list<object{hour: string, method: string, staff_id: int, staff_name: string, amount: string}>
     */
    public function moneyReceivedByHour(string $start, string $end, ?int $doctorId): array;

    /**
     * Refunds between two UTC instants ($end excluded), per UTC hour, method, and staff member.
     *
     * @return list<object{hour: string, method: string, staff_id: int, staff_name: string, amount: string}>
     */
    public function moneyRefundedByHour(string $start, string $end, ?int $doctorId): array;

    /**
     * Charges dated in a period, per doctor. Money covers active charges only (FR-010).
     *
     * @return list<object{doctor_id: int, active_charges: string, cancelled_charges: string, fees: string, discounts: string, amount_paid: string}>
     */
    public function billingByDoctor(string $from, string $to, ?int $doctorId): array;

    /**
     * Seen visits dated in a period with no active charge, per doctor.
     *
     * @return list<object{doctor_id: int, visits: int}>
     */
    public function unchargedSeenVisitsByDoctor(string $from, string $to, ?int $doctorId): array;

    /**
     * The doctors with these ids, by account name, then id.
     *
     * @param  list<int>  $ids
     * @return list<object{id: int, title: string|null, name: string}>
     */
    public function doctorsByName(array $ids): array;
}
