<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Appointments\Http\Requests\ListAppointmentsRequest;
use App\Modules\Payments\Enums\ChargeStatus;
use App\Modules\Payments\Models\Charge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListChargesRequest extends FormRequest
{
    private const FIELDS = ['from', 'to', 'patient_id', 'doctor_id', 'status'];

    public function authorize(): bool
    {
        return $this->user()->can('viewAny', Charge::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'patient_id' => ['nullable', 'integer', 'min:1'],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', Rule::in(ChargeStatus::values())],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return array_combine(
            self::FIELDS,
            array_map(fn (string $key) => __("payments::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * At most 92 days, shared with the appointment lists (research R8).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => ListAppointmentsRequest::validateRange($validator, $this->input('from'), $this->input('to'))];
    }

    /**
     * The range and the filters that were sent, typed.
     *
     * @return array{from: string, to: string, patient_id?: int, doctor_id?: int, status?: string}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FIELDS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        foreach (['patient_id', 'doctor_id'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
