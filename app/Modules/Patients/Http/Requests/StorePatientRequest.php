<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PatientRules::normalizePhones($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = PatientRules::fields();
        $rules['national_id'][] = Rule::unique('patients', 'national_id');

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PatientRules::attributes();
    }
}
