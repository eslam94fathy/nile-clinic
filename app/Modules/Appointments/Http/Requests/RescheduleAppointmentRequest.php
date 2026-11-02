<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Http\Requests\Concerns\ValidatesBookableDate;
use App\Modules\Appointments\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RescheduleAppointmentRequest extends FormRequest
{
    use ValidatesBookableDate;

    public function authorize(): bool
    {
        return $this->user()->can('update', Appointment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->bookableDateRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'date' => __('appointments::attributes.date'),
            'start' => __('appointments::attributes.start'),
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateBookableDate($validator)];
    }
}
