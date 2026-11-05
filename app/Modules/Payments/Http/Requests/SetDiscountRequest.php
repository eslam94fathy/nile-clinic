<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class SetDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('discount', Charge::class);
    }

    /**
     * Zero is allowed: it removes a discount (research R6).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:100000'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'amount' => __('payments::attributes.amount'),
            'reason' => __('payments::attributes.reason'),
        ];
    }

    /**
     * The amount as an exact two-decimal string.
     */
    public function amount(): string
    {
        return Money::fromPiastres(Money::toPiastres($this->validated('amount')));
    }

    public function reason(): string
    {
        return $this->validated('reason');
    }
}
