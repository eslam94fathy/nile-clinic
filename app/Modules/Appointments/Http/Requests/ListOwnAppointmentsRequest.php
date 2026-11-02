<?php

namespace App\Modules\Appointments\Http\Requests;

use App\Modules\Appointments\Enums\AppointmentStatus;
use App\Modules\Appointments\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ListOwnAppointmentsRequest extends FormRequest
{
    private const FIELDS = ['from', 'to', 'status'];

    public function authorize(): bool
    {
        return $this->user()->can('viewOwn', Appointment::class);
    }

    /**
     * The same range rules as the staff list (FR-015, research R10).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', Rule::in(AppointmentStatus::values())],
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
            array_map(fn (string $key) => __("appointments::attributes.{$key}"), self::FIELDS),
        );
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [fn (Validator $validator) => ListAppointmentsRequest::validateRange($validator, $this->input('from'), $this->input('to'))];
    }

    /**
     * @return array{from: string, to: string, status?: string}
     */
    public function filters(): array
    {
        return array_filter(
            $this->safe()->only(self::FIELDS),
            fn (mixed $value) => $value !== null && $value !== '',
        );
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
