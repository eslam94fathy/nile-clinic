<?php

namespace App\Modules\Reports\Repositories;

use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Reports\Repositories\Contracts\ReportRepository;
use Illuminate\Database\Query\JoinClause;
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

    public function billingByDoctor(string $from, string $to, ?int $doctorId): array
    {
        $cancelled = ChargeStatus::Cancelled->value;

        return DB::table('charges')
            ->whereBetween('date', [$from, $to])
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
            ->select('doctor_id')
            ->selectRaw('SUM(status <> ?) as active_charges', [$cancelled])
            ->selectRaw('SUM(status = ?) as cancelled_charges', [$cancelled])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN fee ELSE 0 END) as fees', [$cancelled])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN discount ELSE 0 END) as discounts', [$cancelled])
            ->selectRaw('SUM(CASE WHEN status <> ? THEN amount_paid ELSE 0 END) as amount_paid', [$cancelled])
            ->groupBy('doctor_id')
            ->get()
            ->all();
    }

    public function unchargedSeenVisitsByDoctor(string $from, string $to, ?int $doctorId): array
    {
        return DB::table('queue_entries')
            ->leftJoin('charges', function (JoinClause $join) {
                $join->on('charges.queue_entry_id', '=', 'queue_entries.id')->where('charges.active_flag', 1);
            })
            ->whereBetween('queue_entries.date', [$from, $to])
            ->where('queue_entries.status', QueueStatus::Seen->value)
            ->whereNull('charges.id')
            ->when($doctorId !== null, fn ($query) => $query->where('queue_entries.doctor_id', $doctorId))
            ->select('queue_entries.doctor_id')
            ->selectRaw('COUNT(*) as visits')
            ->groupBy('queue_entries.doctor_id')
            ->get()
            ->all();
    }

    public function appointmentsByDoctor(string $from, string $to, ?int $doctorId): array
    {
        return DB::table('appointments')
            ->whereBetween('date', [$from, $to])
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
            ->select('doctor_id', 'status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('doctor_id', 'status')
            ->get()
            ->all();
    }

    public function visitsByDoctor(string $from, string $to, ?int $doctorId): array
    {
        return DB::table('queue_entries')
            ->whereBetween('date', [$from, $to])
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
            ->select('doctor_id', 'status')
            ->selectRaw('COUNT(*) as count, COUNT(appointment_id) as booked')
            ->groupBy('doctor_id', 'status')
            ->get()
            ->all();
    }

    public function patientsRegistered(string $start, string $end): int
    {
        return DB::table('patients')
            ->where('created_at', '>=', $start)
            ->where('created_at', '<', $end)
            ->count();
    }

    public function visitTimesByDoctor(string $from, string $to, ?int $doctorId): array
    {
        return DB::table('queue_entries')
            ->whereBetween('date', [$from, $to])
            ->when($doctorId !== null, fn ($query) => $query->where('doctor_id', $doctorId))
            ->select('doctor_id')
            ->selectRaw('COUNT(called_at) as wait_visits')
            ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, arrived_at, called_at)) as wait_seconds')
            ->selectRaw('SUM(called_at IS NOT NULL AND finished_at IS NOT NULL) as consultation_visits')
            ->selectRaw('SUM(TIMESTAMPDIFF(SECOND, called_at, finished_at)) as consultation_seconds')
            ->groupBy('doctor_id')
            ->get()
            ->all();
    }

    public function recordsByDoctor(string $from, string $to, ?int $doctorId): array
    {
        return DB::table('queue_entries')
            ->leftJoin('encounters', 'encounters.queue_entry_id', '=', 'queue_entries.id')
            ->whereBetween('queue_entries.date', [$from, $to])
            ->where('queue_entries.status', QueueStatus::Seen->value)
            ->when($doctorId !== null, fn ($query) => $query->where('queue_entries.doctor_id', $doctorId))
            ->select('queue_entries.doctor_id', 'encounters.status as record_status')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('queue_entries.doctor_id', 'encounters.status')
            ->get()
            ->all();
    }

    public function doctorsByName(array $ids): array
    {
        return DB::table('doctors')
            ->join('users', 'users.id', '=', 'doctors.user_id')
            ->whereIn('doctors.id', $ids)
            ->select('doctors.id', 'doctors.title', 'users.name')
            ->orderBy('users.name')
            ->orderBy('doctors.id')
            ->get()
            ->all();
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
