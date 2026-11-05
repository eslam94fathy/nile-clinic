<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Enums\PaymentMethod;
use App\Modules\Payments\Models\Charge;
use App\Modules\Payments\Support\Money;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('refund', Charge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100000'],
            'method' => ['required', Rule::in(PaymentMethod::values())],
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
            'method' => __('payments::attributes.method'),
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

    public function paymentMethod(): PaymentMethod
    {
        return PaymentMethod::from($this->validated('method'));
    }

    public function reason(): string
    {
        return $this->validated('reason');
    }
}
