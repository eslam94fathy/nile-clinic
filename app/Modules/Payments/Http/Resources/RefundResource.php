<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Refund
 */
class RefundResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => $this->amount,
            'method' => $this->method->value,
            'reason' => $this->reason,
            'refunded_by' => ['id' => $this->refundedBy->id, 'name' => $this->refundedBy->name],
            'refunded_at' => $this->created_at->toISOString(),
        ];
    }
}
