<?php

namespace App\Modules\Doctors\Http\Requests;

use App\Modules\Doctors\Models\Doctor;
use App\Support\ArabicName;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateDoctorRequest extends FormRequest
{
    private const FIELDS = ['title', 'registration_number', 'specialty_ids'];

    public function authorize(): bool
    {
        return $this->user()->can('update', Doctor::class);
    }

    /**
     * Clean the title first, so its length is checked on the value that is stored.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('title'))) {
            $this->merge(['title' => ArabicName::clean($this->input('title'))]);
        }
    }

    /**
     * Partial update: each field is validated only when sent. There is no user_id rule, so the
     * linked account never reaches validated() and cannot change.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'min:1', 'max:100'],
            'registration_number' => [
                'sometimes', 'nullable', 'string', 'regex:'.StoreDoctorRequest::REGISTRATION_NUMBER_PATTERN,
                Rule::unique('doctors', 'registration_number')->ignore((int) $this->route('doctor')),
            ],
            'specialty_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'specialty_ids.*' => ['integer', 'distinct', Rule::exists('specialties', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return StoreDoctorRequest::attributeNames();
    }

    /**
     * At least one updatable field must be sent (contract: minProperties 1).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                if (! $this->hasAny(self::FIELDS)) {
                    $validator->errors()->add('fields', __('doctors::messages.nothing_to_update'));
                }
            },
        ];
    }
}
