<?php

namespace App\Modules\Encounters\Http\Requests;

use App\Modules\Encounters\Models\Encounter;
use Illuminate\Foundation\Http\FormRequest;

class ListPatientEncountersRequest extends FormRequest
{
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
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 15);
    }
}
