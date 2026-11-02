<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;

class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', Appointment::class);
    }

    /**
     * The reason is trimmed; an empty reason is no reason (research R11).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('reason'))) {
            $reason = trim($this->input('reason'));
            $this->merge(['reason' => $reason === '' ? null : $reason]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['reason' => __('appointments::attributes.reason')];
    }
}
