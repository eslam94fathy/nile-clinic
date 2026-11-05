<?php

namespace App\Modules\Payments\Http\Resources;

use App\Modules\Payments\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Payment
 */
class PaymentResource extends JsonResource
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
            'received_by' => ['id' => $this->receivedBy->id, 'name' => $this->receivedBy->name],
            'received_at' => $this->created_at->toISOString(),
        ];
    }
}
