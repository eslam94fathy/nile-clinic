<?php

namespace App\Modules\Encounters\Http\Requests;

use App\Modules\Appointments\Http\Requests\ListAppointmentsRequest;
use App\Modules\Encounters\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListOwnVisitsRequest extends FormRequest
{
    private const FIELDS = ['from', 'to', 'patient_id', 'record_status'];

    public function authorize(): bool
    {
        return $this->user()->can('access', Encounter::class);
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
            'record_status' => ['nullable', Rule::in(['none', 'draft', 'finalized'])],
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
            array_map(fn (string $key) => __("encounters::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * At most 92 days, shared with the appointment lists (research R9).
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
     * @return array{from: string, to: string, patient_id?: int, record_status?: string}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(self::FIELDS),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        if (isset($filters['patient_id'])) {
            $filters['patient_id'] = (int) $filters['patient_id'];
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
