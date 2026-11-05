<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Charge;
use App\Modules\Staff\Models\User;
use Illuminate\Http\Request;

/**
 * One full charge: its figures, reasons, and every payment and refund (FR-018).
 *
 * @mixin Charge
 */
class ChargeResource extends ChargeSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),
            'charged_by' => self::staff($this->chargedBy),
            'discount_reason' => $this->discount_reason,
            'discounted_by' => self::staff($this->discountedBy),
            'discounted_at' => $this->discounted_at?->toISOString(),
            'cancel_reason' => $this->cancel_reason,
            'cancelled_by' => self::staff($this->cancelledBy),
            'cancelled_at' => $this->cancelled_at?->toISOString(),
            'payments' => PaymentResource::collection($this->payments),
            'refunds' => RefundResource::collection($this->refunds),
        ];
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private static function staff(?User $user): ?array
    {
        return $user === null ? null : ['id' => $user->id, 'name' => $user->name];
    }
}
