<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\DoctorFee;
use App\Modules\Payments\Support\Money;
use Illuminate\Foundation\Http\FormRequest;

class SetFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('set', DoctorFee::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:100000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['amount' => __('payments::attributes.amount')];
    }

    /**
     * The amount as an exact two-decimal string.
     */
    public function amount(): string
    {
        return Money::fromPiastres(Money::toPiastres($this->validated('amount')));
    }
}
