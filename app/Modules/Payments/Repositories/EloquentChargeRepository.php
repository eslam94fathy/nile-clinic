<?php

namespace App\Modules\Payments\Repositories;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Repositories\Contracts\ChargeRepository;
use App\Modules\Queues\Enums\QueueStatus;
use App\Modules\Queues\Models\QueueEntry;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

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

    public function paginateVisits(string $date, array $filters, int $perPage): LengthAwarePaginator
    {
        return QueueEntry::query()
            ->select(
                'queue_entries.*',
                'charges.id as charge_id',
                'charges.status as charge_status',
                'charges.fee as charge_fee',
                'charges.discount as charge_discount',
                'charges.amount_paid as charge_amount_paid',
            )
            ->leftJoin('charges', function (JoinClause $join) {
                $join->on('charges.queue_entry_id', '=', 'queue_entries.id')->where('charges.active_flag', 1);
            })
            ->where('queue_entries.date', $date)
            ->where(fn (Builder $query) => $query
                ->where('queue_entries.status', '!=', QueueStatus::Left)
                ->orWhereNotNull('charges.id'))
            ->when(isset($filters['doctor_id']), fn (Builder $query) => $query->where('queue_entries.doctor_id', $filters['doctor_id']))
            ->when(isset($filters['charge_status']), function (Builder $query) use ($filters) {
                $filters['charge_status'] === 'none'
                    ? $query->whereNull('charges.id')
                    : $query->where('charges.status', $filters['charge_status']);
            })
            ->with(['patient', 'doctor.user'])
            ->orderBy('queue_entries.doctor_id')
            ->orderBy('queue_entries.order_minute')
            ->orderBy('queue_entries.queue_number')
            ->paginate($perPage)
            ->withQueryString();
    }

    public function paginate(array $filters, int $perPage): LengthAwarePaginator
    {
        return Charge::query()
            ->whereBetween('date', [$filters['from'], $filters['to']])
            ->when(isset($filters['patient_id']), fn (Builder $query) => $query->where('patient_id', $filters['patient_id']))
            ->when(isset($filters['doctor_id']), fn (Builder $query) => $query->where('doctor_id', $filters['doctor_id']))
            ->when(isset($filters['status']), fn (Builder $query) => $query->where('status', $filters['status']))
            ->with(['patient', 'doctor.user'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }
}
