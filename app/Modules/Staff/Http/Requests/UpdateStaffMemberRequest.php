<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\EmailRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', User::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => EmailRules::normalize($this->input('email'))]);
        }
    }

    /**
     * At least one of name, email, role (FR-018); each is validated only when sent.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required_without_all:email,role', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                ...EmailRules::rules(),
                Rule::unique('users', 'email')->ignore((int) $this->route('staffMember')),
            ],
            'role' => ['sometimes', 'required', Rule::enum(Role::class)],
        ];
    }
}
