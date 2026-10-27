<?php

namespace App\Modules\Staff\Http\Requests;

use App\Modules\Staff\Enums\Role;
use App\Modules\Staff\Models\User;
use App\Modules\Staff\Support\EmailRules;
use App\Modules\Staff\Support\PasswordRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', User::class);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge(['email' => EmailRules::normalize($this->input('email'))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', ...EmailRules::rules(), Rule::unique('users', 'email')],
            'role' => ['required', Rule::enum(Role::class)],
            'password' => PasswordRules::rules(),
        ];
    }
}
