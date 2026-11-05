<?php

namespace App\Modules\Payments\Http\Requests;

use App\Modules\Payments\Models\DoctorFee;
use Illuminate\Foundation\Http\FormRequest;

class ListFeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('viewAny', DoctorFee::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
