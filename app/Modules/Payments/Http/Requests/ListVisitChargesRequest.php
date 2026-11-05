<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\Charge;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListVisitChargesRequest extends FormRequest
{
    private const FIELDS = ['date', 'doctor_id', 'charge_status'];

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
            'date' => ['required', 'date_format:Y-m-d'],
            'doctor_id' => ['nullable', 'integer', 'min:1'],
            'charge_status' => ['nullable', Rule::in(['none', 'unpaid', 'partially_paid', 'paid'])],
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
     * The filters that were sent, typed.
     *
     * @return array{doctor_id?: int, charge_status?: string}
     */
    public function filters(): array
    {
        $filters = array_filter(
            $this->safe()->only(['doctor_id', 'charge_status']),
            fn (mixed $value) => $value !== null && $value !== '',
        );

        if (isset($filters['doctor_id'])) {
            $filters['doctor_id'] = (int) $filters['doctor_id'];
        }

        return $filters;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
