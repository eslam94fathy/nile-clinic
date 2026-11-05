<?php

namespace App\Modules\Reports\Repositories;

use App\Modules\Reports\Repositories\Contracts\ReportRepository;
use Illuminate\Support\Facades\DB;

/**
 * The query builder only, with no models: encrypted casts never run, and only aggregates are selected
 * (research R2, data model).
 */
class QueryReportRepository implements ReportRepository
{
    public function moneyReceivedByHour(string $start, string $end, ?int $doctorId): array
    {
        return $this->moneyByHour('payments', 'received_by', $start, $end, $doctorId);
    }

    public function moneyRefundedByHour(string $start, string $end, ?int $doctorId): array
    {
        return $this->moneyByHour('refunds', 'refunded_by', $start, $end, $doctorId);
    }

    /**
     * Grouped by UTC hour, because PHP alone knows the clinic's time zone (research R4).
     *
     * @return list<object{hour: string, method: string, staff_id: int, staff_name: string, amount: string}>
     */
    private function moneyByHour(string $table, string $staffColumn, string $start, string $end, ?int $doctorId): array
    {
        return DB::table($table)
            ->join('users', 'users.id', '=', "{$table}.{$staffColumn}")
            ->when($doctorId !== null, fn ($query) => $query
                ->join('charges', 'charges.id', '=', "{$table}.charge_id")
                ->where('charges.doctor_id', $doctorId))
            ->where("{$table}.created_at", '>=', $start)
            ->where("{$table}.created_at", '<', $end)
            ->selectRaw("DATE_FORMAT({$table}.created_at, '%Y-%m-%d %H:00:00') as hour")
            ->addSelect("{$table}.method", 'users.id as staff_id', 'users.name as staff_name')
            ->selectRaw("SUM({$table}.amount) as amount")
            ->groupBy('hour', "{$table}.method", 'users.id', 'users.name')
            ->get()
            ->all();
    }
}
