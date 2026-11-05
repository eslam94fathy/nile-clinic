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
}
