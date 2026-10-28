<?php

namespace App\Modules\Patients\Http\Requests;

use App\Modules\Patients\Models\Patient;
use App\Modules\Patients\Support\PatientRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdatePatientRequest extends FormRequest
{
    private const FIELDS = ['full_name', 'date_of_birth', 'sex', 'phone', 'national_id', 'address', 'emergency_contact'];

    public function authorize(): bool
    {
        return $this->user()->can('update', Patient::class);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(PatientRules::normalizePhones($this->all()));
    }

    /**
     * Partial update: each top-level field is validated only when sent; required fields cannot be
     * cleared. Nested emergency_contact rules apply only when the object is sent.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = PatientRules::fields();

        foreach (self::FIELDS as $field) {
            array_unshift($rules[$field], 'sometimes');
        }

        $rules['national_id'][] = Rule::unique('patients', 'national_id')->ignore((int) $this->route('patient'));

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return PatientRules::attributes();
    }

    /**
     * At least one patient field must be sent (contract: minProperties 1).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('fields', __('patients::messages.nothing_to_update'));
                }
            },
        ];
    }
}
