<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\Charge;
use Illuminate\Foundation\Http\FormRequest;

class CancelChargeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('cancel', Charge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => __('payments::attributes.reason')];
    }

    public function reason(): string
    {
        return $this->validated('reason');
    }
}
